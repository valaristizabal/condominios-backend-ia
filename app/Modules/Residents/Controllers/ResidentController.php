<?php

namespace App\Modules\Residents\Controllers;

use App\Http\Controllers\Controller;
use App\\Modules\\Core\\Models\\Apartment;
use App\Modules\Residents\Models\Resident;
use App\Modules\Security\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'type' => ['nullable', Rule::in(['propietario', 'arrendatario'])],
            'unit_type_name' => ['nullable', 'string', 'max:100'],
        ]);

        $residents = Resident::query()
            ->with(['user', 'apartment.unitType:id,name'])
            ->whereHas('apartment', fn ($q) => $q->where('condominium_id', $activeCondominiumId))
            ->when(
                ! empty($validated['q']),
                function ($query) use ($validated) {
                    $search = trim((string) $validated['q']);
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery->whereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('full_name', 'like', '%' . $search . '%')
                                ->orWhere('document_number', 'like', '%' . $search . '%');
                        })->orWhereHas('apartment', function ($apartmentQuery) use ($search) {
                            $apartmentQuery->where('number', 'like', '%' . $search . '%')
                                ->orWhere('tower', 'like', '%' . $search . '%');
                        });
                    });
                }
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', (bool) $validated['is_active'])
            )
            ->when(
                ! empty($validated['type']),
                fn ($query) => $query->where('type', $validated['type'])
            )
            ->when(
                ! empty($validated['unit_type_name']),
                fn ($query) => $query->whereHas(
                    'apartment.unitType',
                    fn ($unitTypeQuery) => $unitTypeQuery->where('name', trim((string) $validated['unit_type_name']))
                )
            )
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($residents);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'document_number' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
            'type' => ['required', Rule::in(['propietario', 'arrendatario'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $apartment = $this->resolveApartmentInActiveCondominium(
            (int) $validated['apartment_id'],
            $activeCondominiumId
        );

        try {
            $resident = DB::transaction(function () use ($validated, $apartment) {
                $user = $this->resolveOrCreateUser($validated);

                $newResident = Resident::query()->create([
                    'user_id' => $user->id,
                    'apartment_id' => $apartment->id,
                    'type' => $validated['type'],
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                return $newResident;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un residente para ese usuario en el apartamento indicado.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($resident->fresh()->load(['user', 'apartment.unitType:id,name']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $resident = Resident::query()
            ->with(['user', 'apartment.unitType:id,name'])
            ->whereHas('apartment', fn ($q) => $q->where('condominium_id', $activeCondominiumId))
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($resident->user_id),
            ],
            'document_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'document_number')->ignore($resident->user_id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'apartment_id' => ['sometimes', 'integer', 'exists:apartments,id'],
            'type' => ['sometimes', Rule::in(['propietario', 'arrendatario'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['apartment_id'])) {
            $this->resolveApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);
        }

        try {
            DB::transaction(function () use ($resident, $validated) {
                $userData = collect($validated)->only([
                    'full_name',
                    'email',
                    'document_number',
                    'phone',
                    'birth_date',
                ])->all();

                if (! empty($userData)) {
                    $resident->user()->update($userData);
                }

                $residentData = collect($validated)->only([
                    'apartment_id',
                    'type',
                    'is_active',
                ])->all();

                if (! empty($residentData)) {
                    $resident->update($residentData);
                }
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un residente para ese usuario en el apartamento indicado.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($resident->fresh()->load(['user', 'apartment.unitType:id,name']));
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

    private function resolveOrCreateUser(array $validated): User
    {
        $email = $validated['email'];
        $documentNumber = $validated['document_number'];

        $userByEmail = User::query()->where('email', $email)->first();
        $userByDocument = User::query()->where('document_number', $documentNumber)->first();

        if ($userByEmail && $userByDocument && $userByEmail->id !== $userByDocument->id) {
            throw ValidationException::withMessages([
                'email' => ['El email pertenece a un usuario diferente al del documento enviado.'],
                'document_number' => ['El documento pertenece a un usuario diferente al del email enviado.'],
            ]);
        }

        $existingUser = $userByEmail ?: $userByDocument;

        if ($existingUser) {
            $existingUser->update([
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'] ?? $existingUser->phone,
                'birth_date' => $validated['birth_date'] ?? $existingUser->birth_date,
            ]);

            return $existingUser;
        }

        return User::query()->create([
            'full_name' => $validated['full_name'],
            'email' => $email,
            'document_number' => $documentNumber,
            'phone' => $validated['phone'] ?? null,
            'birth_date' => $validated['birth_date'] ?? null,
            'password' => Str::random(24),
            'is_active' => true,
            'is_platform_admin' => false,
        ]);
    }
}




