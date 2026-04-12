<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserManagementAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        // Platform admin: puede gestionar usuarios globalmente.
        if ($user->is_platform_admin) {
            return $next($request);
        }

        // Tenant admin: puede gestionar usuarios solo dentro de su condominio activo.
        $isTenantAdmin = $user->roles()->whereIn('name', [
            'Administrador Propiedad',
            'administrador_propiedad',
            'admin_condominio',
        ])->exists();

        if (! $isTenantAdmin) {
            return response()->json([
                'message' => 'Acceso denegado. Requiere Super Usuario o Administrador Propiedad.',
            ], 403);
        }

        return $next($request);
    }
}

