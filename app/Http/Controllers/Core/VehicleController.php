<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'owner_type' => ['nullable', 'string', Rule::in(['resident', 'visitor', 'provider'])],
            'is_active' => ['nullable', 'boolean'],
            'plate' => ['nullable', 'string', 'max:20'],
        ]);

        $query = Vehicle::query()
            ->with([
                'vehicleType:id,name,is_active',
                'apartment:id,unit_type_id,tower,number,floor',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('id');

        if (! empty($validated['owner_type'])) {
            $query->where('owner_type', $validated['owner_type']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        if (! empty($validated['plate'])) {
            $query->where('plate', 'like', '%'.$validated['plate'].'%');
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'vehicle_type_id' => ['required', 'integer', 'exists:vehicle_types,id'],
            'apartment_id' => ['nullable', 'integer', 'exists:apartments,id'],
            'plate' => [
                'required',
                'string',
                'max:20',
                Rule::unique('vehicles', 'plate')->where(
                    fn ($query) => $query->where('condominium_id', $activeCondominiumId)
                ),
            ],
            'owner_type' => ['required', 'string', Rule::in(['resident', 'visitor', 'provider'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $vehicleType = $this->resolveVehicleTypeInActiveCondominium(
            (int) $validated['vehicle_type_id'],
            $activeCondominiumId
        );

        $apartmentId = null;
        if (! empty($validated['apartment_id'])) {
            $apartment = $this->resolveApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);
            $apartmentId = $apartment->id;
        }

        try {
            $vehicle = Vehicle::query()->create([
                'condominium_id' => $activeCondominiumId,
                'vehicle_type_id' => $vehicleType->id,
                'apartment_id' => $apartmentId,
                'plate' => strtoupper(trim($validated['plate'])),
                'owner_type' => $validated['owner_type'],
                'is_active' => $validated['is_active'] ?? true,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un vehiculo con esa placa en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(
            $vehicle->fresh()->load([
                'vehicleType:id,name,is_active',
                'apartment:id,unit_type_id,tower,number,floor',
            ]),
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $vehicle = Vehicle::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'vehicle_type_id' => ['sometimes', 'required', 'integer', 'exists:vehicle_types,id'],
            'apartment_id' => ['sometimes', 'nullable', 'integer', 'exists:apartments,id'],
            'plate' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('vehicles', 'plate')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId))
                    ->ignore($vehicle->id),
            ],
            'owner_type' => ['sometimes', 'required', 'string', Rule::in(['resident', 'visitor', 'provider'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('vehicle_type_id', $validated)) {
            $this->resolveVehicleTypeInActiveCondominium((int) $validated['vehicle_type_id'], $activeCondominiumId);
        }

        if (array_key_exists('apartment_id', $validated) && ! empty($validated['apartment_id'])) {
            $this->resolveApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);
        }

        if (array_key_exists('plate', $validated)) {
            $validated['plate'] = strtoupper(trim($validated['plate']));
        }

        try {
            $vehicle->update($validated);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un vehiculo con esa placa en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(
            $vehicle->fresh()->load([
                'vehicleType:id,name,is_active',
                'apartment:id,unit_type_id,tower,number,floor',
            ])
        );
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $vehicle = Vehicle::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $vehicle->is_active = ! $vehicle->is_active;
        $vehicle->save();

        return response()->json([
            'message' => $vehicle->is_active ? 'Vehiculo activado.' : 'Vehiculo desactivado.',
            'data' => $vehicle,
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

    private function resolveVehicleTypeInActiveCondominium(int $vehicleTypeId, int $activeCondominiumId): VehicleType
    {
        $vehicleType = VehicleType::query()
            ->where('id', $vehicleTypeId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $vehicleType) {
            throw ValidationException::withMessages([
                'vehicle_type_id' => ['El tipo de vehiculo no pertenece al condominio activo.'],
            ]);
        }

        return $vehicleType;
    }

    private function resolveApartmentInActiveCondominium(int $apartmentId, int $activeCondominiumId): Apartment
    {
        $apartment = Apartment::query()
            ->where('id', $apartmentId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $apartment) {
            throw ValidationException::withMessages([
                'apartment_id' => ['El apartamento no pertenece al condominio activo.'],
            ]);
        }

        return $apartment;
    }
}
