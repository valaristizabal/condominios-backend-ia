<?php

namespace App\Modules\Emergencies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergencies\Models\EmergencyContact;
use App\Modules\Emergencies\Models\EmergencyType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmergencyContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:all,active,inactive'],
            'emergency_type_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);

        $query = EmergencyContact::query()
            ->with(['emergencyType:id,name,level,is_active'])
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name');

        if (($validated['active'] ?? false) === true) {
            $query->where('is_active', true);
            return response()->json($query->get());
        }

        $hasPaginationOrFilters = $request->query->has('page')
            || $request->query->has('per_page')
            || $request->query->has('q')
            || $request->query->has('status')
            || $request->query->has('emergency_type_id');

        if (! $hasPaginationOrFilters) {
            return response()->json($query->get());
        }

        $status = (string) ($validated['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! empty($validated['emergency_type_id'])) {
            $query->where('emergency_type_id', (int) $validated['emergency_type_id']);
        }

        if (! empty($validated['q'])) {
            $search = trim((string) $validated['q']);
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone_number', 'like', '%'.$search.'%')
                    ->orWhereHas('emergencyType', function ($typeQuery) use ($search) {
                        $typeQuery->where('name', 'like', '%'.$search.'%');
                    });
            });
        }

        $contacts = $query->paginate(
            (int) ($validated['per_page'] ?? 6),
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json($contacts);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('emergency_contacts', 'name')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId)),
            ],
            'phone_number' => ['required', 'string', 'max:30'],
            'emergency_type_id' => ['nullable', 'integer', 'exists:emergency_types,id'],
            'icon' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertEmergencyTypeBelongsToCondominium(
            $validated['emergency_type_id'] ?? null,
            $activeCondominiumId
        );

        $contact = EmergencyContact::query()->create([
            'condominium_id' => $activeCondominiumId,
            'emergency_type_id' => $validated['emergency_type_id'] ?? null,
            'name' => trim($validated['name']),
            'phone_number' => trim($validated['phone_number']),
            'icon' => $validated['icon'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'data' => $contact->fresh()->load(['emergencyType:id,name,level,is_active']),
            'warning' => $this->duplicatePhoneWarning(
                $activeCondominiumId,
                $validated['phone_number'],
                (int) $contact->id
            ),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $contact = EmergencyContact::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('emergency_contacts', 'name')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId))
                    ->ignore($contact->id),
            ],
            'phone_number' => ['sometimes', 'required', 'string', 'max:30'],
            'emergency_type_id' => ['sometimes', 'nullable', 'integer', 'exists:emergency_types,id'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
        }

        if (array_key_exists('phone_number', $validated)) {
            $validated['phone_number'] = trim($validated['phone_number']);
        }

        if (array_key_exists('emergency_type_id', $validated)) {
            $this->assertEmergencyTypeBelongsToCondominium($validated['emergency_type_id'], $activeCondominiumId);
        }

        $contact->update($validated);

        return response()->json([
            'data' => $contact->fresh()->load(['emergencyType:id,name,level,is_active']),
            'warning' => array_key_exists('phone_number', $validated)
                ? $this->duplicatePhoneWarning($activeCondominiumId, $validated['phone_number'], (int) $contact->id)
                : null,
        ]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $contact = EmergencyContact::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $contact->is_active = ! $contact->is_active;
        $contact->save();

        return response()->json([
            'message' => $contact->is_active
                ? 'Contacto de emergencia activado.'
                : 'Contacto de emergencia desactivado.',
            'data' => $contact->fresh()->load(['emergencyType:id,name,level,is_active']),
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

    private function assertEmergencyTypeBelongsToCondominium(?int $emergencyTypeId, int $activeCondominiumId): void
    {
        if (! $emergencyTypeId) {
            return;
        }

        $belongsToCondominium = EmergencyType::query()
            ->where('id', $emergencyTypeId)
            ->where('condominium_id', $activeCondominiumId)
            ->exists();

        if (! $belongsToCondominium) {
            throw ValidationException::withMessages([
                'emergency_type_id' => ['El tipo de emergencia no pertenece al condominio activo.'],
            ]);
        }
    }

    private function duplicatePhoneWarning(int $activeCondominiumId, string $phoneNumber, int $ignoreId = 0): ?string
    {
        $duplicates = EmergencyContact::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('phone_number', trim($phoneNumber))
            ->when($ignoreId > 0, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->count();

        if ($duplicates < 1) {
            return null;
        }

        return 'Advertencia: este numero telefonico ya esta asociado a otro contacto de emergencia.';
    }
}
