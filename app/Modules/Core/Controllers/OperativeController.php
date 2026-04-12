<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Operative;
use App\Modules\Security\Models\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperativeController extends Controller
{
    private const ALWAYS_BLOCKED_ROLE_NAMES = ['Super Usuario', 'super_usuario', 'super_admin'];
    private const TENANT_BLOCKED_ADMIN_ROLE_NAMES = ['Administrador Propiedad', 'administrador_propiedad', 'admin_condominio'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'contract_type' => ['nullable', Rule::in(['contratista', 'planta'])],
        ]);

        $activeCondominiumId = $this->resolveActiveCondominiumIdForIndex($request);

        if ($activeCondominiumId <= 0) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => (int) ($validated['per_page'] ?? 10),
                'total' => 0,
            ]);
        }

        $operatives = Operative::query()
            ->with(['user.roles' => fn ($q) => $q->select('roles.id', 'roles.name')])
            ->where('condominium_id', $activeCondominiumId)
            ->when(
                ! empty($validated['q']),
                function ($query) use ($validated) {
                    $search = trim((string) $validated['q']);
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery->whereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('full_name', 'like', '%' . $search . '%')
                                ->orWhere('document_number', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        })->orWhere('position', 'like', '%' . $search . '%');
                    });
                }
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', (bool) $validated['is_active'])
            )
            ->when(
                ! empty($validated['contract_type']),
                fn ($query) => $query->where('contract_type', $validated['contract_type'])
            )
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        $operatives->setCollection(
            $operatives->getCollection()->map(
                fn (Operative $operative) => $this->formatOperative($operative, $activeCondominiumId)
            )
        );

        return response()->json($operatives);
    }

    public function roles(Request $request): JsonResponse
    {
        $isPlatformAdmin = (bool) $request->user()?->is_platform_admin;
        $blockedRoles = $isPlatformAdmin
            ? self::ALWAYS_BLOCKED_ROLE_NAMES
            : array_values(array_unique([
                ...self::ALWAYS_BLOCKED_ROLE_NAMES,
                ...self::TENANT_BLOCKED_ADMIN_ROLE_NAMES,
            ]));

        $roles = Role::query()
            ->where('is_active', true)
            ->whereNotIn('name', $blockedRoles)
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromPayload($request);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'document_number' => ['required', 'string', 'max:50', 'unique:users,document_number'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'position' => ['nullable', 'string', 'max:120'],
            'contract_type' => ['required', Rule::in(['contratista', 'planta'])],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'financial_institution' => ['nullable', 'string', 'max:120'],
            'account_type' => ['nullable', Rule::in(['ahorros', 'corriente'])],
            'account_number' => ['nullable', 'string', 'max:60'],
            'eps' => ['nullable', 'string', 'max:120'],
            'arl' => ['nullable', 'string', 'max:120'],
            'contract_start_date' => ['nullable', 'date'],
        ]);

        $role = $this->resolveOperativeRole(
            (int) $validated['role_id'],
            (bool) $request->user()?->is_platform_admin
        );

        try {
            $operative = DB::transaction(function () use ($validated, $activeCondominiumId, $role) {
                $user = User::query()->create([
                    'full_name' => $validated['full_name'],
                    'document_number' => $validated['document_number'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'phone' => $validated['phone'] ?? null,
                    'birth_date' => $validated['birth_date'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                    'is_platform_admin' => false,
                ]);

                $newOperative = Operative::query()->create([
                    'user_id' => $user->id,
                    'condominium_id' => $activeCondominiumId,
                    'position' => $validated['position'] ?? $role->name,
                    'contract_type' => $validated['contract_type'],
                    'salary' => $validated['salary'] ?? null,
                    'financial_institution' => $validated['financial_institution'] ?? null,
                    'account_type' => $validated['account_type'] ?? null,
                    'account_number' => $validated['account_number'] ?? null,
                    'eps' => $validated['eps'] ?? null,
                    'arl' => $validated['arl'] ?? null,
                    'contract_start_date' => $validated['contract_start_date'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                ]);

                UserRole::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'condominium_id' => $activeCondominiumId,
                    ],
                    [
                        'role_id' => $role->id,
                    ]
                );

                return $newOperative;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe un operativo para ese usuario en el condominio activo.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(
            $this->formatOperative($operative->fresh()->load(['user', 'user.roles']), $activeCondominiumId),
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromPayload($request);

        $operative = Operative::query()
            ->with('user')
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->first();

        if (! $operative) {
            return response()->json([
                'message' => 'Operativo no encontrado para el condominio activo.',
            ], 404);
        }

        $currentUserId = (int) ($operative->user?->id ?? $operative->user_id ?? 0);

        $validated = $request->validate([
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'position' => ['sometimes', 'string', 'max:120'],
            'contract_type' => ['sometimes', Rule::in(['contratista', 'planta'])],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'financial_institution' => ['nullable', 'string', 'max:120'],
            'account_type' => ['nullable', Rule::in(['ahorros', 'corriente'])],
            'account_number' => ['nullable', 'string', 'max:60'],
            'eps' => ['nullable', 'string', 'max:120'],
            'arl' => ['nullable', 'string', 'max:120'],
            'contract_start_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'document_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'document_number')->ignore($currentUserId, 'id'),
            ],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($currentUserId, 'id'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
        ]);

        $selectedRole = null;
        if (isset($validated['role_id'])) {
            $selectedRole = $this->resolveOperativeRole(
                (int) $validated['role_id'],
                (bool) $request->user()?->is_platform_admin
            );
        }

        DB::transaction(function () use ($operative, $validated, $activeCondominiumId, $selectedRole) {
            $userData = collect($validated)->only([
                'full_name',
                'document_number',
                'email',
                'phone',
                'birth_date',
                'is_active',
            ])->all();

            if (! empty($userData)) {
                $operative->user()->update($userData);
            }

            $operativeData = collect($validated)->only([
                'position',
                'contract_type',
                'salary',
                'financial_institution',
                'account_type',
                'account_number',
                'eps',
                'arl',
                'contract_start_date',
                'is_active',
            ])->all();

            if (! empty($operativeData)) {
                $operative->update($operativeData);
            }

            if ($selectedRole) {
                UserRole::query()->updateOrCreate(
                    [
                        'user_id' => $operative->user_id,
                        'condominium_id' => $activeCondominiumId,
                    ],
                    [
                        'role_id' => $selectedRole->id,
                    ]
                );

                if (! isset($validated['position'])) {
                    $operative->update([
                        'position' => $selectedRole->name,
                    ]);
                }
            }
        });

        return response()->json(
            $this->formatOperative($operative->fresh()->load(['user', 'user.roles']), $activeCondominiumId)
        );
    }

    private function resolveActiveCondominiumId(Request $request): int
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($activeCondominiumId <= 0) {
            throw ValidationException::withMessages([
                'condominium' => ['No hay condominio activo resuelto para esta operación.'],
            ]);
        }

        return $activeCondominiumId;
    }

    private function resolveActiveCondominiumIdForIndex(Request $request): int
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($activeCondominiumId > 0) {
            return $activeCondominiumId;
        }

        $user = $request->user();
        if (! $user) {
            return 0;
        }

        // Super admin can set tenant context by header.
        if ($user->is_platform_admin) {
            $headerCondominiumId = (int) $request->header('X-Active-Condominium-Id', 0);
            return $headerCondominiumId > 0 ? $headerCondominiumId : 0;
        }

        // Tenant user fallback: resolve from pivot in case middleware context is missing.
        $role = $user->roles()->first();
        if ($role && isset($role->pivot->condominium_id)) {
            return (int) $role->pivot->condominium_id;
        }

        return 0;
    }

    private function rejectCondominiumIdFromPayload(Request $request): void
    {
        if ($request->exists('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }

    private function resolveOperativeRole(int $roleId, bool $isPlatformAdmin): Role
    {
        $role = Role::query()->findOrFail($roleId);

        if (in_array($role->name, self::ALWAYS_BLOCKED_ROLE_NAMES, true)) {
            throw ValidationException::withMessages([
                'role_id' => ['No se permite asignar roles administrativos a operativos.'],
            ]);
        }

        if (! $isPlatformAdmin && in_array($role->name, self::TENANT_BLOCKED_ADMIN_ROLE_NAMES, true)) {
            throw ValidationException::withMessages([
                'role_id' => ['No se permite asignar roles administrativos a operativos.'],
            ]);
        }

        if (! $role->is_active) {
            throw ValidationException::withMessages([
                'role_id' => ['El rol seleccionado no esta activo.'],
            ]);
        }

        return $role;
    }

    private function formatOperative(Operative $operative, int $activeCondominiumId): array
    {
        $matchedRole = $operative->user?->roles
            ?->first(fn ($role) => (int) $role->pivot->condominium_id === $activeCondominiumId);

        return [
            'id' => $operative->id,
            'user_id' => $operative->user_id,
            'condominium_id' => $operative->condominium_id,
            'position' => $operative->position,
            'contract_type' => $operative->contract_type,
            'salary' => $operative->salary,
            'financial_institution' => $operative->financial_institution,
            'account_type' => $operative->account_type,
            'account_number' => $operative->account_number,
            'eps' => $operative->eps,
            'arl' => $operative->arl,
            'contract_start_date' => $operative->contract_start_date,
            'is_active' => $operative->is_active,
            'created_at' => $operative->created_at,
            'updated_at' => $operative->updated_at,
            'role' => $matchedRole ? ['id' => $matchedRole->id, 'name' => $matchedRole->name] : null,
            'user' => $operative->user,
        ];
    }
}






