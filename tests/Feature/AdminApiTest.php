<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\CredentialService;
use App\Enums\CredentialKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telpo.hashids_salt' => 'sal-de-prueba']);
    }

    private function device(array $attrs = []): Device
    {
        $device = new Device;
        $device->install_id = (string) Str::uuid();
        $device->token_hash = hash('sha256', Str::random(40));
        $device->unit_number = $attrs['unit_number'] ?? 'U-01';
        $device->imei = $attrs['imei'] ?? '356938035643809';
        $device->save();

        return $device;
    }

    public function test_admin_routes_require_login(): void
    {
        $this->getJson('/api/admin/devices')->assertUnauthorized();
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_login_validates_input_and_hides_whether_user_exists(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => 'una-clave-larga-1']);

        $this->postJson('/api/admin/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $wrongUser = $this->postJson('/api/admin/login', ['email' => 'x@example.com', 'password' => 'lo-que-sea-12']);
        $wrongPass = $this->postJson('/api/admin/login', ['email' => 'a@example.com', 'password' => 'incorrecta-123']);
        $wrongUser->assertUnprocessable();
        $this->assertSame($wrongUser->json('errors.email'), $wrongPass->json('errors.email'));

        // Un intento de inyeccion SQL es solo texto: no autentica y no rompe nada.
        $this->postJson('/api/admin/login', ['email' => "a@example.com' OR '1'='1", 'password' => "' OR '1'='1"])->assertUnprocessable();

        $this->postJson('/api/admin/login', ['email' => 'a@example.com', 'password' => 'una-clave-larga-1'])
            ->assertOk()->assertJsonPath('email', 'a@example.com');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/login', ['email' => 'a@example.com', 'password' => 'incorrecta-123'])->assertUnprocessable();
        }
        $this->postJson('/api/admin/login', ['email' => 'a@example.com', 'password' => 'incorrecta-123'])->assertStatus(429);
    }

    public function test_device_routes_use_hashed_ids_and_reject_numeric_ones(): void
    {
        $user = User::factory()->create();
        $device = $this->device();

        $this->actingAs($user)->getJson('/api/admin/devices/'.$device->getRouteKey())->assertOk()
            ->assertJsonPath('data.id', $device->getRouteKey());
        $this->actingAs($user)->getJson('/api/admin/devices/'.$device->id)->assertNotFound();
        $this->actingAs($user)->getJson('/api/admin/devices/'.str_repeat('a', 12))->assertNotFound();
    }

    public function test_devices_search_treats_wildcards_as_text(): void
    {
        $user = User::factory()->create();
        $this->device(['unit_number' => 'U-01', 'imei' => '356938035643801']);
        $this->device(['unit_number' => 'U-02', 'imei' => '356938035643802']);

        $this->actingAs($user)->getJson('/api/admin/devices?q=U-01')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson('/api/admin/devices?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/admin/devices?sort=imei;drop table devices')->assertUnprocessable();
    }

    public function test_changing_a_password_requires_admin_password_and_distinct_values(): void
    {
        $user = User::factory()->create(['password' => 'una-clave-larga-1']);
        $service = app(CredentialService::class);
        $service->set(CredentialKind::Settings, 'config-1234', $user);

        $this->actingAs($user)->putJson('/api/admin/credentials/power', [
            'new_password' => 'energia-1234', 'new_password_confirmation' => 'energia-1234', 'admin_password' => 'mal',
        ])->assertUnprocessable()->assertJsonValidationErrors('admin_password');

        $this->actingAs($user)->putJson('/api/admin/credentials/power', [
            'new_password' => 'config-1234', 'new_password_confirmation' => 'config-1234', 'admin_password' => 'una-clave-larga-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password');

        $this->actingAs($user)->putJson('/api/admin/credentials/power', [
            'new_password' => 'energia-1234', 'new_password_confirmation' => 'energia-1234', 'admin_password' => 'una-clave-larga-1',
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->actingAs($user)->putJson('/api/admin/credentials/otra', [])->assertNotFound();
    }

    public function test_export_filename_follows_ex_t20_pattern(): void
    {
        $user = User::factory()->create();
        $this->device();

        $response = $this->actingAs($user)->get('/api/admin/export?dataset=devices&format=csv&scope=day&date=2026-10-15');
        $response->assertOk();
        $this->assertStringContainsString('EX-T20-2026-10-15.csv', $response->headers->get('content-disposition'));

        $this->actingAs($user)->getJson('/api/admin/export?dataset=devices&format=exe')->assertUnprocessable();
    }

    public function test_dashboard_responds_with_empty_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('kpis.devices_total', 0)
            ->assertJsonCount(3, 'credentials_missing');
    }
}
