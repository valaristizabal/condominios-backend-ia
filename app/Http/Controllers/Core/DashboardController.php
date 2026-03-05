<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Operative;
use App\Models\Resident;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    private const VISIT_STATUS_INSIDE = 'INSIDE';

    public function summary(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $cacheKey = sprintf('dashboard_summary:%d', $activeCondominiumId);
        $summary = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($activeCondominiumId) {
            $operativesCount = Operative::query()
                ->where('condominium_id', $activeCondominiumId)
                ->count();

            $residentsCount = Resident::query()
                ->whereHas('apartment', fn ($q) => $q->where('condominium_id', $activeCondominiumId))
                ->count();

            $visitorsInsideCount = Visit::query()
                ->where('condominium_id', $activeCondominiumId)
                ->where('status', self::VISIT_STATUS_INSIDE)
                ->count();

            return [
                'operatives_count' => $operativesCount,
                'residents_count' => $residentsCount,
                'visitors_inside_count' => $visitorsInsideCount,
            ];
        });

        return response()->json($summary);
    }

    private function resolveActiveCondominiumId(Request $request): int
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($activeCondominiumId <= 0) {
            throw ValidationException::withMessages([
                'condominium' => ['No hay condominio activo resuelto para esta operacion.'],
            ]);
        }

        return $activeCondominiumId;
    }

    private function rejectCondominiumIdFromRequest(Request $request): void
    {
        if ($request->query->has('condominium_id') || $request->request->has('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }
}
