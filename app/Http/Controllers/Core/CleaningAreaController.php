<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\CleaningArea;
use App\Models\CleaningAreaChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CleaningAreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $query = CleaningArea::query()
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name')
            ->orderByDesc('id');

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        if (! empty($validated['name'])) {
            $query->where('name', 'like', '%'.$validated['name'].'%');
        }

        return response()->json(
            $query->withCount('checklistTemplateItems')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cleaning_areas', 'name')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId)),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $area = CleaningArea::query()->create([
            'condominium_id' => $activeCondominiumId,
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($area->fresh()->loadCount('checklistTemplateItems'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($id, $activeCondominiumId);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('cleaning_areas', 'name')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId))
                    ->ignore($area->id),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
        }

        $area->update($validated);

        return response()->json($area->fresh()->loadCount('checklistTemplateItems'));
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($id, $activeCondominiumId);
        $area->is_active = ! $area->is_active;
        $area->save();

        return response()->json([
            'message' => $area->is_active ? 'Area de aseo activada.' : 'Area de aseo desactivada.',
            'data' => $area,
        ]);
    }

    public function indexChecklist(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($id, $activeCondominiumId);

        return response()->json(
            $area->checklistTemplateItems()->orderBy('id')->get()
        );
    }

    public function storeChecklistItem(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($id, $activeCondominiumId);

        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
        ]);

        $item = $area->checklistTemplateItems()->create([
            'item_name' => trim($validated['item_name']),
        ]);

        return response()->json($item, 201);
    }

    public function destroyChecklistItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($id, $activeCondominiumId);

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

    private function resolveCleaningAreaInActiveCondominium(int $id, int $activeCondominiumId): CleaningArea
    {
        $area = CleaningArea::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->first();

        if (! $area) {
            throw ValidationException::withMessages([
                'cleaning_area_id' => ['El area de aseo no pertenece al condominio activo.'],
            ]);
        }

        return $area;
    }
}
