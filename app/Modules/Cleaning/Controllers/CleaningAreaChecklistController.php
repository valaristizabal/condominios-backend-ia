<?php

namespace App\Modules\Cleaning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cleaning\Models\CleaningArea;
use App\Modules\Cleaning\Models\CleaningAreaChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CleaningAreaChecklistController extends Controller
{
    public function index(Request $request, int $areaId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($areaId, $activeCondominiumId);

        return response()->json(
            $area->checklistTemplateItems()->orderBy('id')->get()
        );
    }

    public function store(Request $request, int $areaId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($areaId, $activeCondominiumId);

        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
        ]);

        $item = $area->checklistTemplateItems()->create([
            'item_name' => trim($validated['item_name']),
        ]);

        return response()->json($item, 201);
    }

    public function destroy(Request $request, int $areaId, int $itemId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($areaId, $activeCondominiumId);

        $item = CleaningAreaChecklist::query()
            ->where('cleaning_area_id', $area->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $item->delete();

        return response()->json([
            'message' => 'Item de checklist eliminado.',
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

    private function resolveCleaningAreaInActiveCondominium(int $areaId, int $activeCondominiumId): CleaningArea
    {
        $area = CleaningArea::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $areaId)
            ->first();

        if (! $area) {
            throw ValidationException::withMessages([
                'cleaning_area_id' => ['El area de aseo no pertenece al condominio activo.'],
            ]);
        }

        return $area;
    }
}

