<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BumpRouteRequest;
use App\Models\AuditLog;
use App\Models\BumpRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rutas para organizar los topes. No cambian a quien le llegan: todas las Telpo reciben todos los topes. */
class BumpRouteController extends Controller
{
    public function index(): JsonResponse
    {
        $routes = BumpRoute::query()->withCount('bumps')->orderBy('name')->get()
            ->map(fn (BumpRoute $r) => ['id' => $r->getRouteKey(), 'name' => $r->name, 'bumps_count' => (int) $r->bumps_count]);

        return response()->json(['data' => $routes]);
    }

    public function store(BumpRouteRequest $request): JsonResponse
    {
        $route = BumpRoute::create(['name' => $request->validated('name')]);
        AuditLog::record('bump_route_created', $request->user()->id, $route->getRouteKey(), ['name' => $route->name], $request->ip());

        return response()->json(['data' => ['id' => $route->getRouteKey(), 'name' => $route->name, 'bumps_count' => 0]], 201);
    }

    public function update(BumpRouteRequest $request, BumpRoute $bumpRoute): JsonResponse
    {
        $bumpRoute->update(['name' => $request->validated('name')]);
        AuditLog::record('bump_route_updated', $request->user()->id, $bumpRoute->getRouteKey(), ['name' => $bumpRoute->name], $request->ip());

        return response()->json(['data' => ['id' => $bumpRoute->getRouteKey(), 'name' => $bumpRoute->name]]);
    }

    /** Borrar una ruta no borra sus topes: solo quita la etiqueta. */
    public function destroy(Request $request, BumpRoute $bumpRoute): JsonResponse
    {
        $key = $bumpRoute->getRouteKey();
        $name = $bumpRoute->name;
        $bumpRoute->delete();
        AuditLog::record('bump_route_deleted', $request->user()->id, $key, ['name' => $name], $request->ip());

        return response()->json(['ok' => true]);
    }
}
