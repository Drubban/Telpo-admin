<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BillingTargetRequest;
use App\Http\Requests\DeviceIndexRequest;
use App\Http\Resources\DeviceResource;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Device;
use App\Support\BillingApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/** Control de la version de la app de cobro instalada en cada Telpo. */
class AppVersionController extends Controller
{
    public function index(DeviceIndexRequest $request): JsonResponse
    {
        $target = BillingApp::target();
        $state = $request->validate(['state' => ['nullable', Rule::in(['current', 'outdated', 'missing', 'unknown'])]])['state'] ?? null;

        $query = Device::query()->filter($request->filters());
        if ($state) {
            $query->billingState($state, $target['code']);
        }

        $page = $query
            ->orderByRaw('(CASE WHEN devices.billing_app_installed IS TRUE AND devices.billing_app_version_code >= ? THEN 1 ELSE 0 END) ASC', [$target['code'] ?? 0])
            ->orderBy('devices.unit_number')->orderBy('devices.id')
            ->paginate((int) ($request->validated('per_page') ?? 15))
            ->withQueryString();

        $count = fn (string $s) => Device::query()->billingState($s, $target['code'])->count();

        return DeviceResource::collection($page)->additional([
            'package' => BillingApp::package(),
            'target' => $target,
            'summary' => [
                'total' => Device::count(),
                'current' => $count('current'),
                'outdated' => $count('outdated'),
                'missing' => $count('missing'),
                'unknown' => $count('unknown'),
            ],
        ])->response();
    }

    public function updateTarget(BillingTargetRequest $request): JsonResponse
    {
        $code = $request->validated('version_code');
        AppSetting::write(BillingApp::KEY_CODE, $code === null ? null : (string) (int) $code);
        AppSetting::write(BillingApp::KEY_NAME, $code === null ? null : ($request->validated('version_name') ?: null));

        AuditLog::record('billing_target_updated', $request->user()->id, BillingApp::package(), [
            'version_code' => $code, 'version_name' => $request->validated('version_name'),
        ], $request->ip());

        return response()->json(['target' => BillingApp::target()]);
    }
}
