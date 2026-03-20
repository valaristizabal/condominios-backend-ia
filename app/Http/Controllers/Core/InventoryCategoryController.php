<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'active' => ['nullable', 'integer', 'in:0,1'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:all,active,inactive'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $query = InventoryCategory::query()
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name');

        if ((int) ($validated['active'] ?? 0) === 1) {
            $query->where('is_active', true);
            return response()->json($query->get(['id', 'name', 'is_active']));
        }

        $hasPaginationOrFilters = $request->query->has('page')
            || $request->query->has('per_page')
            || $request->query->has('q')
            || $request->query->has('status');

        if (! $hasPaginationOrFilters) {
            $query->where('is_active', true);
            return response()->json($query->get(['id', 'name', 'is_active']));
        }

        $status = (string) ($validated['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! empty($validated['q'])) {
            $search = trim((string) $validated['q']);
            $query->where('name', 'like', '%' . $search . '%');
        }

        $categories = $query->paginate(
            (int) ($validated['per_page'] ?? 12),
            ['id', 'name', 'is_active'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = InventoryCategory::query()->create([
            'condominium_id' => $activeCondominiumId,
            'name' => trim($validated['name']),
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        ]);

        return response()->json($category->only(['id', 'name', 'is_active']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $category = InventoryCategory::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }

        $category->update($validated);

        return response()->json($category->fresh()->only(['id', 'name', 'is_active']));
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $category = InventoryCategory::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $category->is_active = ! $category->is_active;
        $category->save();

        return response()->json([
            'message' => $category->is_active ? 'Categoria activada.' : 'Categoria desactivada.',
            'data' => $category->only(['id', 'name', 'is_active']),
        ]);
    }

    private function activeCondominium(Request $request): int
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
