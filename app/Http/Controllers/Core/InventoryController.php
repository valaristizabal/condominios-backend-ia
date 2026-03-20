<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
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

        $query = Inventory::query()
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name')
            ->select(['id', 'name', 'is_active']);

        if ((int) ($validated['active'] ?? 0) === 1) {
            $query->where('is_active', true);
            return response()->json($query->get());
        }

        $hasPaginationOrFilters = $request->query->has('page')
            || $request->query->has('per_page')
            || $request->query->has('q')
            || $request->query->has('status');

        if (! $hasPaginationOrFilters) {
            $query->where('is_active', true);
            return response()->json($query->get());
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

        $inventories = $query->paginate(
            (int) ($validated['per_page'] ?? 12),
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json($inventories);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $inventory = Inventory::query()->create([
            'condominium_id' => $activeCondominiumId,
            'name' => trim($validated['name']),
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        ]);

        return response()->json(
            $inventory->only(['id', 'name', 'is_active']),
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $inventory = Inventory::query()
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

        $inventory->update($validated);

        return response()->json(
            $inventory->fresh()->only(['id', 'name', 'is_active'])
        );
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $inventory = Inventory::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $inventory->is_active = ! $inventory->is_active;
        $inventory->save();

        return response()->json([
            'message' => $inventory->is_active ? 'Inventario activado.' : 'Inventario desactivado.',
            'data' => $inventory->only(['id', 'name', 'is_active']),
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
