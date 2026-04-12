<?php

namespace App\Modules\Emergencies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergencies\Models\EmergencyContact;
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:6'],
        ]);

        $query = EmergencyContact::query()
            ->where('condominium_id', $activeCondominiumId)
            ->orderBy('name');

        if (($validated['active'] ?? false) === true) {
            $query->where('is_active', true);
            return response()->json($query->get());
        }

        $hasPaginationOrFilters = $request->query->has('page')
            || $request->query->has('per_page')
            || $request->query->has('q')
            || $request->query->has('status');

        if (! $hasPaginationOrFilters) {
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
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone_number', 'like', '%' . $search . '%');
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
            'icon' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $contact = EmergencyContact::query()->create([
            'condominium_id' => $activeCondominiumId,
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
            'icon' => $validated['icon'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($contact, 201);
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
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $contact->update($validated);

        return response()->json($contact->fresh());
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
            'data' => $contact,
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
}



