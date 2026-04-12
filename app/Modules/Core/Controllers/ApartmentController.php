<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\UnitType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:100'],
            'tower' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $apartments = Apartment::query()
            ->with(['unitType:id,name'])
            ->where('condominium_id', $activeCondominiumId)
            ->when(
                ! empty($validated['q']),
                function ($query) use ($validated) {
                    $q = trim((string) $validated['q']);
                    $query->where(function ($subQuery) use ($q) {
                        $subQuery->where('number', 'like', '%' . $q . '%')
                            ->orWhere('tower', 'like', '%' . $q . '%');
                    });
                }
            )
            ->when(
                ! empty($validated['tower']) && trim((string) $validated['tower']) !== 'all',
                fn ($query) => $query->where('tower', trim((string) $validated['tower']))
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', (bool) $validated['is_active'])
            )
            ->orderBy('tower')
            ->orderBy('number')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($apartments);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'unit_type_id' => ['required', 'integer', 'exists:unit_types,id'],
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('apartments', 'number')->where(
                    fn ($q) => $q->where('condominium_id', $activeCondominiumId)
                ),
            ],
            'tower' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->resolveUnitTypeInActiveCondominium((int) $validated['unit_type_id'], $activeCondominiumId);

        try {
            $apartment = Apartment::query()->create([
                'condominium_id' => $activeCondominiumId,
                'unit_type_id' => $validated['unit_type_id'],
                'number' => $validated['number'],
                'tower' => $validated['tower'] ?? null,
                'floor' => $validated['floor'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe ese numero de apartamento en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($apartment->fresh()->load(['unitType:id,name']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $apartment = Apartment::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'unit_type_id' => ['sometimes', 'required', 'integer', 'exists:unit_types,id'],
            'number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('apartments', 'number')
                    ->where(fn ($q) => $q->where('condominium_id', $activeCondominiumId))
                    ->ignore($apartment->id),
            ],
            'tower' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['unit_type_id'])) {
            $this->resolveUnitTypeInActiveCondominium((int) $validated['unit_type_id'], $activeCondominiumId);
        }

        try {
            $apartment->update($validated);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe ese numero de apartamento en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($apartment->fresh()->load(['unitType:id,name']));
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $apartment = Apartment::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $apartment->is_active = ! $apartment->is_active;
        $apartment->save();

        return response()->json([
            'message' => $apartment->is_active ? 'Apartamento activado.' : 'Apartamento desactivado.',
            'data' => $apartment,
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

    private function resolveUnitTypeInActiveCondominium(int $unitTypeId, int $activeCondominiumId): UnitType
    {
        $unitType = UnitType::query()
            ->where('id', $unitTypeId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $unitType) {
            throw ValidationException::withMessages([
                'unit_type_id' => ['El tipo de unidad no pertenece al condominio activo.'],
            ]);
        }

        return $unitType;
    }
}




