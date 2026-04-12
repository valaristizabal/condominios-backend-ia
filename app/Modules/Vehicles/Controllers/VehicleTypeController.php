<?php

namespace App\Modules\Vehicles\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vehicles\Models\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VehicleTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'active' => ['nullable', 'integer', 'in:0,1'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:all,active,inactive'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $query = VehicleType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name');

        if ((int) ($validated['active'] ?? 0) === 1) {
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

        $vehicleTypes = $query->paginate(
            (int) ($validated['per_page'] ?? 12),
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json($vehicleTypes);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $vehicleType = VehicleType::query()->create([
            'condominium_id' => $activeCondominiumId,
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return response()->json($vehicleType, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $vehicleType = VehicleType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $vehicleType->update($validated);

        return response()->json($vehicleType->fresh());
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $vehicleType = VehicleType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $vehicleType->is_active = ! $vehicleType->is_active;
        $vehicleType->save();

        return response()->json([
            'message' => $vehicleType->is_active ? 'Tipo de vehiculo activado.' : 'Tipo de vehiculo desactivado.',
            'data' => $vehicleType,
        ]);
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



