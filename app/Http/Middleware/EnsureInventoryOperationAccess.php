<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInventoryOperationAccess
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

        if ($user->userHasModulePermission('inventory', $activeCondominiumId)) {
            return $next($request);
        }

        $role = $user->roles()
            ->when($activeCondominiumId > 0, fn ($query) => $query->where('user_role.condominium_id', $activeCondominiumId))
            ->first();

        $normalizedRole = $this->normalizeRoleName($role?->name);

        $adminRoles = [
            'administrador propiedad',
            'administrador_propiedad',
            'admin condominio',
            'admin_condominio',
        ];

        $operationHints = ['operativ', 'seguridad', 'vigilante', 'porteria', 'porteria'];

        if (in_array($normalizedRole, $adminRoles, true)) {
            return $next($request);
        }

        foreach ($operationHints as $hint) {
            if (str_contains($normalizedRole, $hint)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Acceso denegado. Requiere perfil operativo o administrador para Inventario.',
        ], 403);
    }

    private function normalizeRoleName(?string $roleName): string
    {
        $name = mb_strtolower(trim((string) $roleName));

        if (function_exists('transliterator_transliterate')) {
            $name = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC;', $name);
        }

        return str_replace(['_', '  '], [' ', ' '], $name);
    }
}

