<?php

namespace App\Modules\Security\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Security\Models\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Models\UserModulePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $query = User::query()->with(['roles:id,name'])
            ->where('is_platform_admin', false)
            ->whereDoesntHave('roles', function ($q) {
                $q->whereIn('name', [
                    'Super Usuario',
                    'super_usuario',
                    'super_admin',
                    'Administrador Propiedad',
                    'administrador_propiedad',
                    'admin_condominio',
                ]);
            });
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($activeCondominiumId > 0) {
            $query->whereHas('roles', function ($q) use ($activeCondominiumId) {
                $q->where('user_role.condominium_id', $activeCondominiumId);
            });
        } elseif (! $authUser->is_platform_admin) {
            $query->whereRaw('1 = 0');
        }

        if (! empty($validated['q'])) {
            $search = trim((string) $validated['q']);
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('full_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('document_number', 'like', '%' . $search . '%');
            });
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'document_number' => ['required', 'string', 'max:50', 'unique:users,document_number'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'is_platform_admin' => ['sometimes', 'boolean'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'condominium_id' => ['nullable', 'integer', 'exists:condominiums,id'],
        ]);

        if (($validated['is_platform_admin'] ?? false) === true) {
            throw ValidationException::withMessages([
                'is_platform_admin' => ['No se permite crear usuarios de plataforma desde este endpoint.'],
            ]);
        }

        $role = Role::query()->findOrFail($validated['role_id']);

        if (in_array($role->name, ['Super Usuario', 'super_usuario', 'super_admin'], true)) {
            throw ValidationException::withMessages([
                'role_id' => ['No se permite asignar role Super Usuario desde este endpoint.'],
            ]);
        }

        $targetCondominiumId = null;

        if ($authUser->is_platform_admin) {
            $targetCondominiumId = $validated['condominium_id'] ?? null;

            if (! $targetCondominiumId) {
                throw ValidationException::withMessages([
                    'condominium_id' => ['El condominio es obligatorio para crear usuarios tenant.'],
                ]);
            }
        } else {
            $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');
            $requestedCondominiumId = $validated['condominium_id'] ?? null;

            if ($requestedCondominiumId && (int) $requestedCondominiumId !== $activeCondominiumId) {
                return response()->json([
                    'message' => 'No puedes crear usuarios en otro condominio.',
                ], 403);
            }

            $targetCondominiumId = $activeCondominiumId;
        }

        $user = DB::transaction(function () use ($validated, $role, $targetCondominiumId) {
            $newUser = User::query()->create([
                'full_name' => $validated['full_name'],
                'document_number' => $validated['document_number'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'password' => $validated['password'],
                'is_active' => $validated['is_active'] ?? true,
                'is_platform_admin' => false,
            ]);

            $newUser->roles()->attach($role->id, [
                'condominium_id' => $targetCondominiumId,
            ]);

            return $newUser;
        });

        return response()->json(
            $user->load(['roles:id,name']),
            201
        );
    }

    public function changePassword(Request $request, int $id): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $targetUser = User::query()->findOrFail($id);

        if (! $authUser->is_platform_admin) {
            $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

            if ($activeCondominiumId <= 0) {
                throw ValidationException::withMessages([
                    'condominium' => ['No hay condominio activo resuelto para esta operación.'],
                ]);
            }

            $isTenantAdmin = $authUser->roles()
                ->where('user_role.condominium_id', $activeCondominiumId)
                ->whereIn('name', [
                    'Administrador Propiedad',
                    'administrador_propiedad',
                    'admin_condominio',
                ])
                ->exists();

            if (! $isTenantAdmin) {
                return response()->json([
                    'message' => 'No tienes permisos para cambiar contraseñas.',
                ], 403);
            }

            $targetBelongsToActiveCondominium = $targetUser->roles()
                ->where('user_role.condominium_id', $activeCondominiumId)
                ->exists();

            if (! $targetBelongsToActiveCondominium) {
                return response()->json([
                    'message' => 'No puedes cambiar la contraseña de usuarios de otro condominio.',
                ], 403);
            }
        }

        $targetUser->password = Hash::make($validated['password']);
        $targetUser->save();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    public function getModulePermissions(Request $request, int $id): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        $this->rejectCondominiumIdFromRequest($request);
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);

        if (! $authUser->is_platform_admin && ! $authUser->isTenantAdmin($activeCondominiumId)) {
            return response()->json([
                'message' => 'No tienes permisos para consultar permisos de módulos.',
            ], 403);
        }

        $targetUser = User::query()->findOrFail($id);

        if (! $authUser->is_platform_admin) {
            $this->ensureUserBelongsToCondominium($targetUser, $activeCondominiumId);
        }

        $permissions = $this->permissionsPayload($targetUser->id, $activeCondominiumId);

        return response()->json($permissions);
    }

    public function saveModulePermissions(Request $request, int $id): JsonResponse
    {
        /** @var User|null $authUser */
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        $this->rejectCondominiumIdFromRequest($request);
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);

        if (! $authUser->is_platform_admin && ! $authUser->isTenantAdmin($activeCondominiumId)) {
            return response()->json([
                'message' => 'No tienes permisos para modificar permisos de módulos.',
            ], 403);
        }

        $targetUser = User::query()->findOrFail($id);

        if (! $authUser->is_platform_admin) {
            $this->ensureUserBelongsToCondominium($targetUser, $activeCondominiumId);
        }

        $validated = $request->validate([
            '*.module' => ['required', 'string', Rule::in(User::AVAILABLE_MODULES)],
            '*.can_view' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $targetUser, $activeCondominiumId) {
            foreach ($validated as $item) {
                UserModulePermission::query()->updateOrCreate(
                    [
                        'user_id' => $targetUser->id,
                        'condominium_id' => $activeCondominiumId,
                        'module' => (string) $item['module'],
                    ],
                    [
                        'can_view' => (bool) $item['can_view'],
                    ]
                );
            }
        });

        return response()->json($this->permissionsPayload($targetUser->id, $activeCondominiumId));
    }

    private function rejectCondominiumIdFromRequest(Request $request): void
    {
        if ($request->query->has('condominium_id') || $request->request->has('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
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

    private function ensureUserBelongsToCondominium(User $user, int $activeCondominiumId): void
    {
        $belongs = $user->roles()
            ->where('user_role.condominium_id', $activeCondominiumId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario no pertenece al condominio activo.'],
            ]);
        }
    }

    private function permissionsPayload(int $userId, int $activeCondominiumId): array
    {
        $existing = UserModulePermission::query()
            ->where('user_id', $userId)
            ->where('condominium_id', $activeCondominiumId)
            ->pluck('can_view', 'module');

        return collect(User::AVAILABLE_MODULES)->map(function (string $module) use ($existing) {
            return [
                'module' => $module,
                'can_view' => (bool) ($existing[$module] ?? false),
            ];
        })->values()->all();
    }
}





