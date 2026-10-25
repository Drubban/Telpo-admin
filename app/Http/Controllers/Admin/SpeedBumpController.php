<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BumpImportRequest;
use App\Http\Requests\SpeedBumpIndexRequest;
use App\Http\Requests\SpeedBumpRequest;
use App\Http\Resources\SpeedBumpResource;
use App\Models\AuditLog;
use App\Models\BumpRoute;
use App\Models\SpeedBump;
use App\Support\BumpCatalog;
use App\Support\BumpImporter;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Administracion de los topes (reductores de velocidad) que avisan las Telpo. */
class SpeedBumpController extends Controller
{
    /** Dos topes a menos de esta distancia (m) se consideran el mismo al importar. */
    private const DUPLICATE_RADIUS_M = 10.0;

    public function index(SpeedBumpIndexRequest $request): JsonResponse
    {
        $page = SpeedBump::query()
            ->with('routes')
            ->filter($request->filters())
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 15))
            ->withQueryString();

        return SpeedBumpResource::collection($page)
            ->additional([
                'routes' => $this->routes(),
                'summary' => [
                    'total' => SpeedBump::count(),
                    'active' => SpeedBump::where('active', true)->count(),
                    'version' => BumpCatalog::version(),
                ],
            ])
            ->response();
    }

    /** Todos los topes activos, para dibujar el mapa de vista previa. */
    public function map(): JsonResponse
    {
        $items = SpeedBump::query()->orderBy('id')->get()->map(fn (SpeedBump $b) => [
            'id' => $b->getRouteKey(),
            'name' => $b->name,
            'latitude' => $b->latitude,
            'longitude' => $b->longitude,
            'active' => $b->active,
        ]);

        return response()->json(['data' => $items]);
    }

    public function store(SpeedBumpRequest $request): JsonResponse
    {
        $data = $request->safe()->only(['name', 'latitude', 'longitude', 'radius_m', 'heading_deg', 'active', 'notes']);

        $bump = DB::transaction(function () use ($data, $request) {
            $bump = SpeedBump::create($data + ['active' => true]);
            $bump->routes()->sync($request->routeIds());

            return $bump;
        });

        AuditLog::record('bump_created', $request->user()->id, $bump->getRouteKey(), ['name' => $bump->name], $request->ip());

        return response()->json(['data' => (new SpeedBumpResource($bump->load('routes')))->resolve($request)], 201);
    }

    public function update(SpeedBumpRequest $request, SpeedBump $bump): JsonResponse
    {
        $data = $request->safe()->only(['name', 'latitude', 'longitude', 'radius_m', 'heading_deg', 'active', 'notes']);

        DB::transaction(function () use ($bump, $data, $request) {
            $bump->fill($data)->save();
            // Si la peticion trae route_ids se reemplazan las rutas; si no, se dejan como estan.
            if ($request->has('route_ids')) {
                $bump->routes()->sync($request->routeIds());
            }
        });

        AuditLog::record('bump_updated', $request->user()->id, $bump->getRouteKey(), ['name' => $bump->name], $request->ip());

        return response()->json(['data' => (new SpeedBumpResource($bump->load('routes')))->resolve($request)]);
    }

    public function destroy(Request $request, SpeedBump $bump): JsonResponse
    {
        $key = $bump->getRouteKey();
        $name = $bump->name;
        $bump->delete();

        AuditLog::record('bump_deleted', $request->user()->id, $key, ['name' => $name], $request->ip());

        return response()->json(['ok' => true]);
    }

    /**
     * Importa coordenadas pegadas como texto. Con dry_run devuelve la revision (nuevos, duplicados y
     * lineas con error) sin guardar nada; sin dry_run guarda los nuevos.
     */
    public function import(BumpImportRequest $request): JsonResponse
    {
        $parsed = BumpImporter::parse((string) $request->validated('text'));
        $existing = SpeedBump::query()->get(['id', 'name', 'latitude', 'longitude']);

        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $row['status'] = 'new';
            $row['duplicate_of'] = null;

            // Se compara contra los topes ya guardados y contra las filas anteriores de esta misma lista.
            $candidates = $existing->map(fn ($b) => ['name' => $b->name, 'lat' => $b->latitude, 'lng' => $b->longitude])
                ->concat(collect($rows)->where('status', 'new')->map(fn ($r) => ['name' => $r['name'], 'lat' => $r['latitude'], 'lng' => $r['longitude']]));
            foreach ($candidates as $c) {
                if (Geo::distanceM($row['latitude'], $row['longitude'], $c['lat'], $c['lng']) <= self::DUPLICATE_RADIUS_M) {
                    $row['status'] = 'duplicate';
                    $row['duplicate_of'] = $c['name'];
                    break;
                }
            }
            $rows[] = $row;
        }

        $new = array_values(array_filter($rows, fn ($r) => $r['status'] === 'new'));
        $summary = [
            'new' => count($new),
            'duplicates' => count($rows) - count($new),
            'errors' => count($parsed['errors']),
        ];

        if ($request->boolean('dry_run')) {
            return response()->json(['rows' => $rows, 'errors' => $parsed['errors'], 'summary' => $summary]);
        }

        DB::transaction(function () use ($new, $request) {
            $routeIds = $request->routeIds();
            foreach ($new as $row) {
                $bump = SpeedBump::create([
                    'name' => $row['name'],
                    'latitude' => $row['latitude'],
                    'longitude' => $row['longitude'],
                    'radius_m' => $request->validated('radius_m'),
                    'heading_deg' => $request->validated('heading_deg'),
                    'active' => true,
                ]);
                if ($routeIds !== []) {
                    $bump->routes()->sync($routeIds);
                }
            }
        });

        AuditLog::record('bump_imported', $request->user()->id, null, $summary, $request->ip());

        return response()->json(['rows' => $rows, 'errors' => $parsed['errors'], 'summary' => $summary], 201);
    }

    /** @return list<array{id:string,name:string,bumps_count:int}> */
    private function routes(): array
    {
        return BumpRoute::query()->withCount('bumps')->orderBy('name')->get()
            ->map(fn (BumpRoute $r) => ['id' => $r->getRouteKey(), 'name' => $r->name, 'bumps_count' => (int) $r->bumps_count])
            ->all();
    }
}
