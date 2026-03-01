<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VehicleTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $query = VehicleType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name');

        if ((int) $request->query('active', 0) === 1) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
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

