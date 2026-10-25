<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\UsageAlert;
use App\Models\User;
use App\Support\BillingCycle;
use App\Support\Ms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsageAlertsAndVersionsTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telpo.enrollment_key' => 'clave-de-prueba',
            'telpo.hashids_salt' => 'sal-de-prueba',
            'telpo.data_plan_gb' => 36,
            'telpo.alert_thresholds' => [25, 50, 75, 90],
            'telpo.alert_emails' => ['ops@example.com'],
            'telpo.alert_whatsapp' => ['+5215512345678'],
            'telpo.whatsapp.driver' => 'twilio',
            'telpo.whatsapp.twilio' => ['sid' => 'ACtest', 'token' => 'tok', 'from' => '+14155238886'],
        ]);
    }

    private function enroll(): string
    {
        return $this->postJson('/api/v1/device/enroll', [
            'enrollment_key' => 'clave-de-prueba',
            'install_id' => (string) Str::uuid(),
            'imei' => '356938035643809',
            'unit_number' => 'U-12',
        ])->assertCreated()->json('device_token');
    }

    private function usage(string $token, float $gb)
    {
        $cycle = BillingCycle::containing();

        return $this->withToken($token)->postJson('/api/v1/device/sync', ['usage' => [
            'period_start_ms' => Ms::of($cycle->start),
            'rx_bytes' => (int) ($gb * self::GB),
            'tx_bytes' => 0,
            'sampled_at_ms' => Ms::of(now()),
        ]])->assertOk();
    }

    public function test_alert_is_created_once_per_threshold_and_notified(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $token = $this->enroll();

        $this->usage($token, 8);   // 22 %: sin alerta
        $this->assertSame(0, UsageAlert::count());

        $this->usage($token, 10);  // 27.8 %: umbral 25
        $this->usage($token, 11);  // mismo umbral: no se repite
        $this->assertSame(1, UsageAlert::count());

        $alert = UsageAlert::firstOrFail();
        $this->assertSame(25, $alert->threshold);
        $this->assertSame('sent', $alert->email_status);
        $this->assertSame('sent', $alert->whatsapp_status);
        Http::assertSentCount(1);
        $this->assertStringContainsString('U-12', app(\App\Services\UsageAlertService::class)->message($alert->load('device')));
    }

    public function test_jump_creates_one_loud_alert_and_records_lower_ones_silently(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
        $token = $this->enroll();

        $this->usage($token, 30); // 83 %: cruza 25, 50 y 75

        $this->assertSame(3, UsageAlert::count());
        $this->assertSame(1, UsageAlert::where('silent', false)->count());
        $this->assertSame(75, UsageAlert::where('silent', false)->value('threshold'));
        Http::assertSentCount(1);

        $this->usage($token, 34); // 94 %: solo el 90 es nuevo
        $this->assertSame(90, UsageAlert::where('silent', false)->orderByDesc('threshold')->value('threshold'));
        $this->assertSame(4, UsageAlert::count());
    }

    public function test_failed_whatsapp_does_not_break_sync_and_is_retried(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'x'], 500)]);
        $token = $this->enroll();

        $this->usage($token, 10)->assertJsonPath('usage', 'stored');
        $alert = UsageAlert::firstOrFail();
        $this->assertSame('failed', $alert->whatsapp_status);
        $this->assertSame('sent', $alert->email_status);

        Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
        $this->artisan('telpo:send-alerts')->assertSuccessful();
        $this->assertSame('sent', $alert->refresh()->whatsapp_status);
        $this->assertNotNull($alert->notified_at);
    }

    public function test_channels_without_recipients_are_skipped(): void
    {
        config(['telpo.alert_emails' => [], 'telpo.whatsapp.driver' => 'none']);
        $this->usage($this->enroll(), 10);

        $alert = UsageAlert::firstOrFail();
        $this->assertSame('skipped', $alert->email_status);
        $this->assertSame('skipped', $alert->whatsapp_status);
    }

    public function test_billing_app_version_is_stored_and_compared_with_target(): void
    {
        $user = User::factory()->create();
        $a = $this->enroll();
        $this->withToken($a)->postJson('/api/v1/device/sync', ['billing_app' => [
            'package' => 'mx.transport.charge', 'installed' => true, 'version_name' => '2.0.0', 'version_code' => 20,
        ]])->assertOk();

        $this->postJson('/api/v1/device/enroll', [
            'enrollment_key' => 'clave-de-prueba', 'install_id' => (string) Str::uuid(), 'imei' => '356938035643810', 'unit_number' => 'U-13',
        ])->assertCreated();
        $second = Device::where('unit_number', 'U-13')->firstOrFail();
        $this->assertNull($second->billing_app_checked_at);

        // Paquete distinto al configurado: se ignora.
        $this->withToken($a)->postJson('/api/v1/device/sync', ['billing_app' => [
            'package' => 'otra.app', 'installed' => false,
        ]])->assertOk();
        $this->assertTrue((bool) Device::where('unit_number', 'U-12')->value('billing_app_installed'));

        // Sin objetivo manual: el objetivo es la version mas alta de la flota (20).
        $this->actingAs($user)->getJson('/api/admin/app-versions')->assertOk()
            ->assertJsonPath('target.code', 20)->assertJsonPath('target.source', 'auto')
            ->assertJsonPath('summary.current', 1)->assertJsonPath('summary.unknown', 1);

        // Objetivo manual mas nuevo: la unidad pasa a desactualizada.
        $this->actingAs($user)->putJson('/api/admin/app-versions/target', ['version_code' => 25, 'version_name' => '2.5.0'])->assertOk();
        $this->actingAs($user)->getJson('/api/admin/app-versions?state=outdated')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('summary.outdated', 1);

        $this->actingAs($user)->putJson('/api/admin/app-versions/target', ['version_code' => 'abc'])->assertUnprocessable();
    }

    public function test_alerts_endpoint_lists_and_marks_seen(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
        $user = User::factory()->create();
        $this->usage($this->enroll(), 10);

        $res = $this->actingAs($user)->getJson('/api/admin/usage-alerts')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('unseen', 1)->assertJsonPath('channels.email', true);
        $id = $res->json('data.0.id');

        $this->actingAs($user)->postJson("/api/admin/usage-alerts/{$id}/seen")->assertOk();
        $this->actingAs($user)->getJson('/api/admin/usage-alerts/summary')->assertJsonPath('unseen', 0);
        $this->actingAs($user)->postJson('/api/admin/usage-alerts/1/seen')->assertNotFound();
    }

    public function test_usage_table_bands_filter_and_export_list(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
        config(['telpo.alert_thresholds' => [25, 50, 75, 90, 100]]);
        $user = User::factory()->create();
        foreach ([['U-1', 5], ['U-2', 10], ['U-3', 20], ['U-4', 40]] as $i => [$unit, $gb]) {
            $token = $this->postJson('/api/v1/device/enroll', [
                'enrollment_key' => 'clave-de-prueba', 'install_id' => (string) Str::uuid(),
                'imei' => '35693803564381'.$i, 'unit_number' => $unit,
            ])->assertCreated()->json('device_token');
            $this->usage($token, $gb);
        }

        // 5 GB = 13.9 % (0), 10 GB = 27.8 % (25), 20 GB = 55.6 % (50), 40 GB = 111 % (100)
        $res = $this->actingAs($user)->getJson('/api/admin/usage')->assertOk();
        $counts = collect($res->json('plan.bands'))->pluck('count', 'key')->all();
        $this->assertSame(1, $counts['0']);
        $this->assertSame(1, $counts['25']);
        $this->assertSame(1, $counts['50']);
        $this->assertSame(1, $counts['100']);

        $this->actingAs($user)->getJson('/api/admin/usage?band=100')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.unit_number', 'U-4')->assertJsonPath('data.0.band', 100);
        $this->actingAs($user)->getJson('/api/admin/usage?band=0')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson('/api/admin/usage?band=77')->assertUnprocessable();

        $csv = $this->actingAs($user)->get('/api/admin/export?dataset=plan&format=csv&band=50')->assertOk()->streamedContent();
        $this->assertStringContainsString('U-3', $csv);
        $this->assertStringNotContainsString('U-4', $csv);
    }

    public function test_bump_alert_events_are_stored_and_grouped_per_bump(): void
    {
        $user = User::factory()->create();
        $token = $this->enroll();
        $events = [];
        foreach ([[7, 'Gomat', 3], [7, 'Gomat', 2], [9, 'Villas', 1]] as [$id, $name, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $events[] = [
                    'uuid' => (string) Str::uuid(), 'type' => 'bump_alert', 'occurred_at_ms' => Ms::of(now()),
                    'detail' => ['bump_id' => $id, 'bump_name' => $name, 'distance_m' => 120, 'speed_kmh' => 35],
                ];
            }
        }
        $res = $this->withToken($token)->postJson('/api/v1/device/sync', ['events' => $events])->assertOk();
        $this->assertCount(6, $res->json('acked_events'));

        $device = Device::firstOrFail();
        $out = $this->actingAs($user)->getJson('/api/admin/devices/'.$device->getRouteKey().'/bump-passes')->assertOk();
        $out->assertJsonPath('total', 6)->assertJsonPath('distinct', 2)->assertJsonPath('by_bump.0.passes', 5);

        // El registro general no se llena de avisos de tope.
        $this->actingAs($user)->getJson('/api/admin/events')->assertOk()->assertJsonCount(0, 'data');
    }
}
