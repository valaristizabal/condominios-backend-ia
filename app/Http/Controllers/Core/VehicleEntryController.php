<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleEntryController extends Controller
{
    private const STATUS_INSIDE = 'INSIDE';
    private const STATUS_OUTSIDE = 'OUTSIDE';

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([self::STATUS_INSIDE, self::STATUS_OUTSIDE])],
            'only_active' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = VehicleEntry::query()
            ->with([
                'vehicle:id,condominium_id,vehicle_type_id,apartment_id,plate,owner_type,is_active',
                'vehicle.vehicleType:id,name,is_active',
                'vehicle.apartment:id,unit_type_id,tower,number,floor',
                'registeredBy:id,full_name,email,document_number',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('check_in_at')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (($validated['only_active'] ?? false) === true) {
            $query->whereNull('check_out_at');
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('check_in_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('check_in_at', '<=', $validated['date_to']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectStatusFromRequest($request);

        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'observations' => ['nullable', 'string'],
        ]);

        $vehicle = $this->resolveVehicleInActiveCondominium((int) $validated['vehicle_id'], $activeCondominiumId);

        $activeEntryExists = VehicleEntry::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('vehicle_id', $vehicle->id)
            ->where('status', self::STATUS_INSIDE)
            ->whereNull('check_out_at')
            ->exists();

        if ($activeEntryExists) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['El vehiculo ya tiene un ingreso activo en porteria.'],
            ]);
        }

        $entry = VehicleEntry::query()->create([
            'condominium_id' => $activeCondominiumId,
            'vehicle_id' => $vehicle->id,
            'registered_by_id' => $request->user()?->id,
            'check_in_at' => now(),
            'check_out_at' => null,
            'status' => self::STATUS_INSIDE,
            'observations' => $validated['observations'] ?? null,
        ]);

        return response()->json(
            $entry->fresh()->load([
                'vehicle:id,condominium_id,vehicle_type_id,apartment_id,plate,owner_type,is_active',
                'vehicle.vehicleType:id,name,is_active',
                'vehicle.apartment:id,unit_type_id,tower,number,floor',
                'registeredBy:id,full_name,email,document_number',
            ]),
            201
        );
    }

    public function checkout(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectStatusFromRequest($request);

        $entry = VehicleEntry::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($entry->status !== self::STATUS_INSIDE) {
            throw ValidationException::withMessages([
                'status' => ['El ingreso no se encuentra en estado INSIDE.'],
            ]);
        }

        $entry->update([
            'status' => self::STATUS_OUTSIDE,
            'check_out_at' => now(),
        ]);

        return response()->json(
            $entry->fresh()->load([
                'vehicle:id,condominium_id,vehicle_type_id,apartment_id,plate,owner_type,is_active',
                'vehicle.vehicleType:id,name,is_active',
                'vehicle.apartment:id,unit_type_id,tower,number,floor',
                'registeredBy:id,full_name,email,document_number',
            ])
        );
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

    private function rejectStatusFromRequest(Request $request): void
    {
        if ($request->query->has('status') || $request->request->has('status')) {
            throw ValidationException::withMessages([
                'status' => ['No se permite enviar status en este endpoint.'],
            ]);
        }
    }

    private function resolveVehicleInActiveCondominium(int $vehicleId, int $activeCondominiumId): Vehicle
    {
        $vehicle = Vehicle::query()
            ->where('id', $vehicleId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['El vehiculo no pertenece al condominio activo.'],
            ]);
        }

        return $vehicle;
    }
}
