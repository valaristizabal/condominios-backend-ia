<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Usuario inactivo.',
            ], 403);
        }

        $plainToken = Str::random(60);

        $user->forceFill([
            'api_token' => hash('sha256', $plainToken),
        ])->save();

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user) {
            $user->forceFill([
                'api_token' => null,
            ])->save();
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Platform admin: usuario global del SaaS, sin aislamiento por tenant.
        if ($user->is_platform_admin) {
            return response()->json([
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role' => 'Super Usuario',
                'condominium_id' => null,
            ]);
        }

        // Tenant user: debe venir de user_role y operar dentro de un condominio.
        $user->load('roles');
        $role = $user->roles->first();

        if (! $role || ! isset($role->pivot->condominium_id)) {
            return response()->json([
                'message' => 'El usuario no tiene rol asignado en user_role.',
            ], 404);
        }

        return response()->json([
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $role->name,
            'condominium_id' => $role->pivot->condominium_id,
        ]);
    }
}
