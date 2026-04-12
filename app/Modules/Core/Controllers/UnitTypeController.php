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

class UnitTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $unitTypes = UnitType::query()
            ->withCount('apartments')
            ->where('condominium_id', $activeCondominiumId)
            ->when(
                ! empty($validated['q']),
                fn ($query) => $query->where('name', 'like', '%' . trim((string) $validated['q']) . '%')
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', (bool) $validated['is_active'])
            )
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($unitTypes);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('unit_types', 'name')->where(
                    fn ($q) => $q->where('condominium_id', $activeCondominiumId)
                ),
            ],
            'allows_residents' => ['sometimes', 'boolean'],
            'requires_parent' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->validateBehaviorFlags($validated);

        try {
            $unitType = UnitType::query()->create([
                'condominium_id' => $activeCondominiumId,
                'name' => $validated['name'],
                'allows_residents' => $validated['allows_residents'] ?? false,
                'requires_parent' => $validated['requires_parent'] ?? false,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe ese tipo de unidad en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($unitType, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $unitType = UnitType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('unit_types', 'name')
                    ->where(fn ($q) => $q->where('condominium_id', $activeCondominiumId))
                    ->ignore($unitType->id),
            ],
            'allows_residents' => ['sometimes', 'boolean'],
            'requires_parent' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->validateBehaviorFlags([
            'allows_residents' => $validated['allows_residents'] ?? $unitType->allows_residents,
            'requires_parent' => $validated['requires_parent'] ?? $unitType->requires_parent,
        ]);
        $this->ensureBehaviorUpdateDoesNotBreakExistingData(
            $unitType,
            (bool) ($validated['allows_residents'] ?? $unitType->allows_residents),
            (bool) ($validated['requires_parent'] ?? $unitType->requires_parent)
        );

        try {
            $unitType->update($validated);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe ese tipo de unidad en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($unitType->fresh());
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $unitType = UnitType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $unitType->is_active = ! $unitType->is_active;
        $unitType->save();

        return response()->json([
            'message' => $unitType->is_active ? 'Tipo de unidad activado.' : 'Tipo de unidad desactivado.',
            'data' => $unitType,
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

    private function validateBehaviorFlags(array $validated): void
    {
        $requiresParent = (bool) ($validated['requires_parent'] ?? false);
        $allowsResidents = (bool) ($validated['allows_residents'] ?? false);

        if ($requiresParent && $allowsResidents) {
            throw ValidationException::withMessages([
                'requires_parent' => ['Un tipo de unidad que depende de otro inmueble no puede registrar residentes directamente.'],
                'allows_residents' => ['Desactiva residentes directos o marca el tipo como independiente.'],
            ]);
        }
    }

    private function ensureBehaviorUpdateDoesNotBreakExistingData(
        UnitType $unitType,
        bool $allowsResidents,
        bool $requiresParent
    ): void {
        $apartments = Apartment::query()
            ->withCount(['residents', 'children'])
            ->where('unit_type_id', $unitType->id);

        $hasResidents = (clone $apartments)->has('residents')->exists();
        $hasChildren = (clone $apartments)->has('children')->exists();
        $hasParentAssigned = (clone $apartments)->whereNotNull('parent_id')->exists();

        if (! $allowsResidents && $hasResidents) {
            throw ValidationException::withMessages([
                'allows_residents' => ['No puedes desactivar residentes directos porque ya existen residentes asociados a inmuebles de este tipo.'],
            ]);
        }

        if (! $allowsResidents && $hasChildren) {
            throw ValidationException::withMessages([
                'allows_residents' => ['No puedes desactivar residentes directos porque ya existen inmuebles hijos que dependen de unidades de este tipo.'],
            ]);
        }

        if ($allowsResidents && $hasParentAssigned) {
            throw ValidationException::withMessages([
                'allows_residents' => ['No puedes permitir residentes directos en un tipo que ya tiene inmuebles asociados como unidades hijas.'],
            ]);
        }

        if ($requiresParent && ($hasResidents || $hasChildren)) {
            throw ValidationException::withMessages([
                'requires_parent' => ['No puedes convertir este tipo en dependiente porque ya existen inmuebles principales o residentes asociados.'],
            ]);
        }

        if ($requiresParent && (clone $apartments)->whereNull('parent_id')->exists()) {
            throw ValidationException::withMessages([
                'requires_parent' => ['No puedes exigir inmueble principal porque ya existen inmuebles de este tipo sin padre asociado.'],
            ]);
        }

        if (! $requiresParent && $hasParentAssigned) {
            throw ValidationException::withMessages([
                'requires_parent' => ['No puedes quitar la dependencia porque ya existen inmuebles de este tipo con padre asociado.'],
            ]);
        }
    }
}



