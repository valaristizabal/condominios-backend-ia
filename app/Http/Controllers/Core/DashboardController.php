<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Operative;
use App\Models\Resident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $operativesCount = Operative::query()
            ->where('condominium_id', $activeCondominiumId)
            ->count();

        $residentsCount = Resident::query()
            ->whereHas('apartment', fn ($q) => $q->where('condominium_id', $activeCondominiumId))
            ->count();

        return response()->json([
            'operatives_count' => $operativesCount,
            'residents_count' => $residentsCount,
        ]);
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

