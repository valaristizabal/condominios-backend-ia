<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $query = User::query()->with(['roles:id,name']);

        if (! $authUser->is_platform_admin) {
            $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

            $query->whereHas('roles', function ($q) use ($activeCondominiumId) {
                $q->where('user_role.condominium_id', $activeCondominiumId);
            });
        }

        return response()->json($query->orderByDesc('id')->get());
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

    private function rejectCondominiumIdFromRequest(Request $request): void
    {
        if ($request->query->has('condominium_id') || $request->request->has('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }
}
