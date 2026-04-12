<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInventorySettingsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        if ($user->is_platform_admin) {
            return $next($request);
        }

        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        $isTenantAdmin = $user->roles()
            ->when($activeCondominiumId > 0, fn ($query) => $query->where('user_role.condominium_id', $activeCondominiumId))
            ->whereIn('name', [
                'Administrador Propiedad',
                'administrador_propiedad',
                'admin_condominio',
                'admin condominio',
            ])
            ->exists();

        if (! $isTenantAdmin) {
            return response()->json([
                'message' => 'Acceso denegado. Requiere Administrador para parametrizar Inventario.',
            ], 403);
        }

        return $next($request);
    }
}


