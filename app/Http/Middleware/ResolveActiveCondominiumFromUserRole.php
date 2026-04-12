<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveCondominiumFromUserRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        // Platform admin: can operate globally and may set a temporary tenant by header.
        if ($user->is_platform_admin) {
            $platformCondominiumId = (int) $request->header('X-Active-Condominium-Id', 0);

            if ($platformCondominiumId > 0) {
                $request->attributes->set('activeCondominiumId', $platformCondominiumId);
            }

            return $next($request);
        }

        // Tenant user: resolve condominium from user_role.
        $role = $user->roles()->first();

        if (! $role || ! isset($role->pivot->condominium_id)) {
            return response()->json([
                'message' => 'El usuario no tiene relacion en user_role.',
            ], 404);
        }

        $request->attributes->set('activeCondominiumId', (int) $role->pivot->condominium_id);

        return $next($request);
    }
}

