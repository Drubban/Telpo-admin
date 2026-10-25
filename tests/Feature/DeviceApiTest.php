<?php

namespace Tests\Feature;

use App\Enums\CredentialKind;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\User;
use App\Services\CredentialService;
use App\Support\BillingCycle;
use App\Support\Ms;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telpo.enrollment_key' => 'clave-de-prueba', 'telpo.hashids_salt' => 'sal-de-prueba']);
    }

    private function enroll(array $extra = []): array
    {
        $response = $this->postJson('/api/v1/device/enroll', array_merge([
            'enrollment_key' => 'clave-de-prueba',
            'install_id' => (string) Str::uuid(),
            'imei' => '356938035643809',
            'unit_number' => 'U-12',
            'app_version' => '1.0.0',
        ], $extra));

        $response->assertCreated();

        return $response->json();
    }

    public function test_enroll_rejects_wrong_key(): void
    {
        $this->postJson('/api/v1/device/enroll', [
            'enrollment_key' => 'otra',
            'install_id' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    public function test_enroll_returns_token_and_stores_only_its_hash(): void
    {
        $data = $this->enroll();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['device_token']);
        $device = Device::firstOrFail();
        $this->assertSame(hash('sha256', $data['device_token']), $device->token_hash);
        $this->assertSame('U-12', $device->unit_number);
    }

    public function test_sync_requires_valid_token(): void
    {
        $this->postJson('/api/v1/device/sync', [])->assertUnauthorized();
        $this->withToken(str_repeat('a', 64))->postJson('/api/v1/device/sync', [])->assertUnauthorized();
    }

    public function test_sync_is_idempotent_and_discards_bad_events_without_failing(): void
    {
        $token = $this->enroll()['device_token'];
        $good = (string) Str::uuid();
        $bad = (string) Str::uuid();

        $payload = ['events' => [
            ['uuid' => $good, 'type' => 'settings_access', 'success' => false, 'detail' => ['purpose' => 'open_settings'], 'occurred_at_ms' => Ms::of(now())],
            ['uuid' => $bad, 'type' => 'no_existe', 'success' => true, 'occurred_at_ms' => Ms::of(now())],
        ]];

        $first = $this->withToken($token)->postJson('/api/v1/device/sync', $payload)->assertOk();
        $this->assertEqualsCanonicalizing([$good, $bad], $first->json('acked_events'));

        $this->withToken($token)->postJson('/api/v1/device/sync', $payload)->assertOk();
        $this->assertSame(1, DeviceEvent::count());
    }

    public function test_usage_is_stored_per_day_and_never_decreases(): void
    {
        $token = $this->enroll()['device_token'];
        $cycle = BillingCycle::containing();
        $usage = fn (int $rx, int $tx) => ['usage' => [
            'period_start_ms' => Ms::of($cycle->start),
            'rx_bytes' => $rx,
            'tx_bytes' => $tx,
            'sampled_at_ms' => Ms::of(now()),
        ]];

        $this->withToken($token)->postJson('/api/v1/device/sync', $usage(1000, 500))->assertOk()->assertJsonPath('usage', 'stored');
        $this->withToken($token)->postJson('/api/v1/device/sync', $usage(900, 400))->assertOk();

        $this->assertDatabaseHas('data_usage_daily', ['total_bytes' => 1500]);
        $this->assertDatabaseCount('data_usage_daily', 1);
    }

    public function test_usage_with_other_period_is_ignored(): void
    {
        $token = $this->enroll()['device_token'];
        $wrong = BillingCycle::containing(CarbonImmutable::now()->subMonths(3));

        $this->withToken($token)->postJson('/api/v1/device/sync', ['usage' => [
            'period_start_ms' => Ms::of($wrong->start),
            'rx_bytes' => 1,
            'tx_bytes' => 1,
            'sampled_at_ms' => Ms::of(now()),
        ]])->assertOk()->assertJsonPath('usage', 'ignored_period');
    }

    public function test_credentials_are_delivered_only_when_newer(): void
    {
        $admin = User::factory()->create();
        app(CredentialService::class)->set(CredentialKind::Settings, 'abc12345', $admin);
        $token = $this->enroll()['device_token'];

        $first = $this->withToken($token)->postJson('/api/v1/device/sync', [])->assertOk();
        $this->assertArrayHasKey('settings', $first->json('credentials'));

        $again = $this->withToken($token)->postJson('/api/v1/device/sync', ['credential_versions' => ['settings' => 1]])->assertOk();
        $this->assertSame([], $again->json('credentials'));
    }

    public function test_pbkdf2_matches_known_vector(): void
    {
        // Mismo vector que usa la app Android en sus pruebas.
        $derived = app(CredentialService::class)->derive('Telpo#2026', 'AAECAwQFBgcICQoLDA0ODw==', 1000);

        $this->assertSame('kvBI7CrJpKp+3BD3oDmyQRZ9tv+I/bbcl0LR9UFNi9s=', $derived['hash']);
    }

    /** Regresion: con contrasenas definidas, el sync no debe dar 500 y debe entregar las credenciales. */
    public function test_sync_returns_credentials_and_saves_events_when_passwords_exist(): void
    {
        $service = app(CredentialService::class);
        $admin = User::factory()->create();
        foreach (CredentialKind::cases() as $kind) {
            $service->set($kind, 'clave-'.$kind->value.'-123', $admin);
        }
        $data = $this->enroll();
        $this->assertCount(3, $data['credentials']);

        $this->withToken($data['device_token'])->postJson('/api/v1/device/sync', [
            'app_version' => '9.9.9',
            'events' => [[
                'uuid' => (string) Str::uuid(),
                'type' => 'settings_access',
                'success' => true,
                'occurred_at_ms' => Ms::of(CarbonImmutable::now('UTC')),
            ]],
        ])->assertOk()->assertJsonStructure(['credentials', 'acked_events', 'bumps']);

        $this->assertSame('9.9.9', Device::firstOrFail()->app_version);
        $this->assertSame(1, DeviceEvent::count());
    }
}
