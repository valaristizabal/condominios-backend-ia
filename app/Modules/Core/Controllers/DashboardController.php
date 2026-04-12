<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Core\Models\Operative;
use App\Modules\Inventory\Models\Product;
use App\Modules\Residents\Models\Resident;
use App\Modules\Visits\Models\Visit;
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

        $validated = $request->validate([
            'inventory_id' => ['nullable', 'integer'],
        ]);
        $inventoryId = (int) ($validated['inventory_id'] ?? 0);

        if ($inventoryId > 0) {
            $inventoryExists = Inventory::query()
                ->where('id', $inventoryId)
                ->where('condominium_id', $activeCondominiumId)
                ->exists();

            if (! $inventoryExists) {
                throw ValidationException::withMessages([
                    'inventory_id' => ['El inventario no pertenece al condominio activo.'],
                ]);
            }
        }

        $cacheKey = sprintf('dashboard_summary:%d:%d', $activeCondominiumId, $inventoryId);
        $summary = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($activeCondominiumId, $inventoryId) {
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

            $inventoryTotalValue = Product::query()
                ->where('is_active', true)
                ->when($inventoryId > 0, fn ($query) => $query->where('inventory_id', $inventoryId))
                ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                    $query->where('condominium_id', $activeCondominiumId);
                })
                ->sum('total_value');

            return [
                'operatives_count' => $operativesCount,
                'residents_count' => $residentsCount,
                'visitors_inside_count' => $visitorsInsideCount,
                'inventory_total_value' => (float) $inventoryTotalValue,
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







