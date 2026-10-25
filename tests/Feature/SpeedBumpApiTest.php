<?php

namespace Tests\Feature;

use App\Models\BumpRoute;
use App\Models\Device;
use App\Models\SpeedBump;
use App\Models\User;
use App\Support\BumpCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpeedBumpApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telpo.enrollment_key' => 'clave-de-prueba', 'telpo.hashids_salt' => 'sal-de-prueba']);
    }

    private function bump(array $attrs = []): SpeedBump
    {
        return SpeedBump::create($attrs + ['name' => 'Villas', 'latitude' => 19.82897, 'longitude' => -99.096018, 'active' => true]);
    }

    public function test_bump_routes_require_login(): void
    {
        $this->getJson('/api/admin/speed-bumps')->assertUnauthorized();
        $this->postJson('/api/admin/speed-bumps/import', ['text' => '19.8, -99.1'])->assertUnauthorized();
    }

    public function test_create_validates_coordinates_and_rejects_swapped_ones(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/admin/speed-bumps', [
            'name' => 'Gomat', 'latitude' => 19.825803, 'longitude' => -99.089835, 'radius_m' => 150,
        ])->assertCreated()->assertJsonPath('data.name', 'Gomat');

        // Latitud y longitud invertidas: se rechaza con un mensaje claro.
        $this->actingAs($user)->postJson('/api/admin/speed-bumps', [
            'name' => 'Mal', 'latitude' => -99.089835, 'longitude' => 19.825803,
        ])->assertUnprocessable()->assertJsonValidationErrors(['latitude']);

        $this->actingAs($user)->postJson('/api/admin/speed-bumps', [
            'name' => '<script>alert(1)</script>', 'latitude' => 19.8, 'longitude' => -99.1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $this->actingAs($user)->postJson('/api/admin/speed-bumps', [
            'name' => 'Radio malo', 'latitude' => 19.8, 'longitude' => -99.1, 'radius_m' => 5000,
        ])->assertUnprocessable()->assertJsonValidationErrors(['radius_m']);
    }

    public function test_bump_routes_use_hashed_ids(): void
    {
        $user = User::factory()->create();
        $bump = $this->bump();

        $this->actingAs($user)->patchJson('/api/admin/speed-bumps/'.$bump->getRouteKey(), [
            'name' => 'Villas 1', 'latitude' => 19.82897, 'longitude' => -99.096018,
        ])->assertOk()->assertJsonPath('data.name', 'Villas 1');

        $this->actingAs($user)->patchJson('/api/admin/speed-bumps/'.$bump->id, [
            'name' => 'x', 'latitude' => 19.8, 'longitude' => -99.1,
        ])->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/admin/speed-bumps/'.$bump->id)->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/admin/speed-bumps/'.$bump->getRouteKey())->assertOk();
        $this->assertDatabaseCount('speed_bumps', 0);
    }

    public function test_import_previews_then_saves_and_skips_duplicates(): void
    {
        $user = User::factory()->create();
        $route = BumpRoute::create(['name' => 'Ruta 1']);
        $this->bump(['name' => 'Existente', 'latitude' => 19.824410, 'longitude' => -99.112018]);

        $text = "coordenada\tubicacion\n19.824410, -99.112018\tCrucero\n19.824679, -99.087739\tRinconada\n19.824260, -99.086968\tRinconada\n-99.1 19.8 mal\n";

        $preview = $this->actingAs($user)->postJson('/api/admin/speed-bumps/import', ['text' => $text, 'dry_run' => true]);
        $preview->assertOk()->assertJsonPath('summary.new', 2)->assertJsonPath('summary.duplicates', 1)->assertJsonPath('summary.errors', 1);
        $this->assertDatabaseCount('speed_bumps', 1); // la revision no guarda nada

        $this->actingAs($user)->postJson('/api/admin/speed-bumps/import', [
            'text' => $text, 'route_ids' => [$route->getRouteKey()], 'radius_m' => 120,
        ])->assertCreated()->assertJsonPath('summary.new', 2);

        $this->assertDatabaseCount('speed_bumps', 3);
        $this->assertSame(2, $route->bumps()->count());
        $this->assertSame(120, SpeedBump::where('name', 'Rinconada 1')->value('radius_m'));
    }

    public function test_search_treats_wildcards_as_text(): void
    {
        $user = User::factory()->create();
        $this->bump(['name' => 'Villas']);
        $this->bump(['name' => 'Gomat', 'latitude' => 19.825803, 'longitude' => -99.089835]);

        $this->actingAs($user)->getJson('/api/admin/speed-bumps?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/admin/speed-bumps?q=vil')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sync_sends_bumps_only_when_version_changed(): void
    {
        $this->bump(['name' => 'Villas']);
        $this->bump(['name' => 'Apagado', 'latitude' => 19.8, 'longitude' => -99.1, 'active' => false]);

        $enroll = $this->postJson('/api/v1/device/enroll', [
            'enrollment_key' => 'clave-de-prueba', 'install_id' => (string) Str::uuid(), 'imei' => '356938035643809',
        ])->assertCreated();
        $token = $enroll->json('device_token');

        // Sin version: llega la lista completa (solo los activos).
        $first = $this->withToken($token)->postJson('/api/v1/device/sync', [])->assertOk();
        $first->assertJsonCount(1, 'bumps.items');
        $version = $first->json('bumps.version');
        $this->assertSame(BumpCatalog::version(), $version);

        // Con la misma version: solo llega la version, sin lista.
        $same = $this->withToken($token)->postJson('/api/v1/device/sync', [
            'bumps_version' => $version,
            'bump_stats' => ['count' => 1, 'alerts_today' => 7, 'gps_status' => 'ok'],
        ])->assertOk();
        $this->assertArrayNotHasKey('items', $same->json('bumps'));

        $device = Device::firstOrFail();
        $this->assertSame(7, $device->bump_alerts_today);
        $this->assertSame('ok', $device->gps_status);

        // Si cambia un tope, la version cambia y vuelve a llegar la lista.
        SpeedBump::where('name', 'Villas')->update(['name' => 'Villas norte']);
        $changed = $this->withToken($token)->postJson('/api/v1/device/sync', ['bumps_version' => $version])->assertOk();
        $this->assertNotSame($version, $changed->json('bumps.version'));
        $changed->assertJsonCount(1, 'bumps.items');

        // Un estado de GPS inventado se rechaza.
        $this->withToken($token)->postJson('/api/v1/device/sync', ['bump_stats' => ['gps_status' => 'hackeado']])->assertUnprocessable();
    }
}
