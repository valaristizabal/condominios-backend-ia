<?php

namespace App\Modules\Vehicles\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\Operative;
use App\Modules\Core\Models\UnitType;
use App\Modules\Vehicles\Models\Vehicle;
use App\Modules\Vehicles\Models\VehicleType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function bootstrapData(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $vehicleTypes = VehicleType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        $unitTypes = UnitType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        $apartments = Apartment::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('tower')
            ->orderBy('number')
            ->get(['id', 'unit_type_id', 'tower', 'number', 'floor', 'is_active']);

        $operatives = Operative::query()
            ->with(['user.roles' => fn ($query) => $query->select('roles.id', 'roles.name')])
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get()
            ->map(function (Operative $operative) use ($activeCondominiumId) {
                $matchedRole = $operative->user?->roles
                    ?->first(fn ($role) => (int) $role->pivot->condominium_id === $activeCondominiumId);

                return [
                    'id' => $operative->id,
                    'user_id' => $operative->user_id,
                    'condominium_id' => $operative->condominium_id,
                    'position' => $operative->position,
                    'contract_type' => $operative->contract_type,
                    'is_active' => (bool) $operative->is_active,
                    'role' => $matchedRole ? ['id' => $matchedRole->id, 'name' => $matchedRole->name] : null,
                    'user' => $operative->user ? [
                        'id' => $operative->user->id,
                        'full_name' => $operative->user->full_name,
                        'email' => $operative->user->email,
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'vehicle_types' => $vehicleTypes,
            'vehicleTypes' => $vehicleTypes,
            'units' => $unitTypes,
            'unitTypes' => $unitTypes,
            'apartments' => $apartments,
            'operatives' => $operatives,
        ]);
    }

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

        return response()->json(
            $query
                ->get()
                ->map(fn (Vehicle $vehicle) => $this->present($vehicle))
                ->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'vehicle_type_id' => ['required', 'integer', 'exists:vehicle_types,id'],
            'apartment_id' => ['nullable', 'integer', 'exists:apartments,id'],
            'has_plate' => ['required', 'boolean'],
            'plate' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('vehicles', 'plate')->where(
                    fn ($query) => $query->where('condominium_id', $activeCondominiumId)
                ),
            ],
            'owner_type' => ['required', 'string', Rule::in(['resident', 'visitor', 'provider'])],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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

        $normalizedPlate = $this->normalizeVehiclePlate($validated['plate'] ?? null);
        if ((bool) $validated['has_plate'] && $normalizedPlate === null) {
            throw ValidationException::withMessages([
                'plate' => ['La placa es obligatoria cuando el vehículo sí tiene placa.'],
            ]);
        }

        try {
            $vehicle = Vehicle::query()->create([
                'condominium_id' => $activeCondominiumId,
                'vehicle_type_id' => $vehicleType->id,
                'apartment_id' => $apartmentId,
                'has_plate' => (bool) $validated['has_plate'],
                'plate' => (bool) $validated['has_plate'] ? $normalizedPlate : null,
                'owner_type' => $validated['owner_type'],
                'photo_path' => null,
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

        if ($request->hasFile('photo')) {
            $this->replacePhoto($vehicle, $request);
        }

        return response()->json($this->present($vehicle->fresh()->load([
            'vehicleType:id,name,is_active',
            'apartment:id,unit_type_id,tower,number,floor',
        ])), 201);
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
            'has_plate' => ['sometimes', 'boolean'],
            'plate' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('vehicles', 'plate')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId))
                    ->ignore($vehicle->id),
            ],
            'owner_type' => ['sometimes', 'required', 'string', Rule::in(['resident', 'visitor', 'provider'])],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('vehicle_type_id', $validated)) {
            $this->resolveVehicleTypeInActiveCondominium((int) $validated['vehicle_type_id'], $activeCondominiumId);
        }

        if (array_key_exists('apartment_id', $validated) && ! empty($validated['apartment_id'])) {
            $this->resolveApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);
        }

        $hasPlate = array_key_exists('has_plate', $validated) ? (bool) $validated['has_plate'] : (bool) $vehicle->has_plate;
        $normalizedPlate = array_key_exists('plate', $validated)
            ? $this->normalizeVehiclePlate($validated['plate'])
            : $this->normalizeVehiclePlate($vehicle->plate);

        if ($hasPlate && $normalizedPlate === null) {
            throw ValidationException::withMessages([
                'plate' => ['La placa es obligatoria cuando el vehículo sí tiene placa.'],
            ]);
        }

        $validated['has_plate'] = $hasPlate;
        $validated['plate'] = $hasPlate ? $normalizedPlate : null;

        unset($validated['photo']);

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

        if ($request->hasFile('photo')) {
            $this->replacePhoto($vehicle, $request);
        }

        return response()->json($this->present($vehicle->fresh()->load([
            'vehicleType:id,name,is_active',
            'apartment:id,unit_type_id,tower,number,floor',
        ])));
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

    private function replacePhoto(Vehicle $vehicle, Request $request): void
    {
        if (! $request->hasFile('photo') || ! $this->hasPhotoColumn()) {
            return;
        }

        if (! empty($vehicle->photo_path) && ! Str::startsWith($vehicle->photo_path, ['http://', 'https://'])) {
            Storage::disk('public')->delete($vehicle->photo_path);
        }

        $path = $request->file('photo')->store(sprintf('condominiums/%d/vehicles/%d', $vehicle->condominium_id, $vehicle->id), 'public');

        $vehicle->photo_path = $path;
        $vehicle->save();
    }

    private function present(Vehicle $vehicle): array
    {
        $data = $vehicle->toArray();
        $data['photo'] = $vehicle->photo_path;
        $data['photo_path'] = $vehicle->photo_path;
        $data['photo_url'] = $this->hasPhotoColumn()
            ? $this->resolvePublicStorageUrl($vehicle->photo_path)
            : null;
        $data['image_url'] = $data['photo_url'];
        $data['imageUrl'] = $data['photo_url'];

        return $data;
    }

    private function hasPhotoColumn(): bool
    {
        static $hasPhotoColumn = null;
        if ($hasPhotoColumn === null) {
            $hasPhotoColumn = Schema::hasColumn('vehicles', 'photo_path');
        }

        return $hasPhotoColumn;
    }

    private function resolvePublicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', 'data:image'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function normalizeVehiclePlate(?string $plate): ?string
    {
        $normalized = strtoupper(trim((string) $plate));

        return $normalized !== '' ? $normalized : null;
    }
}




