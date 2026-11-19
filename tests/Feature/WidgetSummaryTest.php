<?php

namespace Tests\Feature;

use App\Support\BillingCycle;
use App\Support\Ms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WidgetSummaryTest extends TestCase
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
            'telpo.alert_thresholds' => [25, 50, 75, 90, 100],
            'telpo.widget_token' => 'token-del-widget',
        ]);
    }

    private function enrollWithUsage(string $unit, float $gb): void
    {
        $token = $this->postJson('/api/v1/device/enroll', [
            'enrollment_key' => 'clave-de-prueba',
            'install_id' => (string) Str::uuid(),
            'imei' => '35693803564'.random_int(1000, 9999),
            'unit_number' => $unit,
        ])->assertCreated()->json('device_token');

        $cycle = BillingCycle::containing();
        $this->withToken($token)->postJson('/api/v1/device/sync', ['usage' => [
            'period_start_ms' => Ms::of($cycle->start),
            'rx_bytes' => (int) ($gb * self::GB),
            'tx_bytes' => 0,
            'sampled_at_ms' => Ms::of(now()),
        ]])->assertOk();
    }

    public function test_route_is_hidden_when_no_token_is_configured(): void
    {
        config(['telpo.widget_token' => '']);
        $this->getJson('/api/v1/widget/summary?token=lo-que-sea')->assertNotFound();
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->getJson('/api/v1/widget/summary?token=otro')->assertUnauthorized();
        $this->getJson('/api/v1/widget/summary')->assertUnauthorized();
    }

    public function test_summary_lists_high_usage_units(): void
    {
        $this->enrollWithUsage('U-1', 10);   // 27.8 %
        $this->enrollWithUsage('U-2', 30);   // 83.3 %
        $this->enrollWithUsage('U-3', 37);   // 102.8 %

        $this->getJson('/api/v1/widget/summary?token=token-del-widget')
            ->assertOk()
            ->assertJsonPath('devices_total', 3)
            ->assertJsonPath('usage_over_75', 2)
            ->assertJsonPath('usage_over_90', 1)
            ->assertJsonPath('usage_over_100', 1)
            ->assertJsonPath('usage_top.0.unit', 'U-3')
            ->assertJsonStructure(['cycle', 'usage_top_text', 'billing_outdated', 'billing_outdated_text', 'generated_at']);

        // Tambien acepta la clave como Bearer.
        $this->withToken('token-del-widget')->getJson('/api/v1/widget/summary')->assertOk();
    }
}
