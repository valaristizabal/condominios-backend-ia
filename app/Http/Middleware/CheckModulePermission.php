<?php

namespace App\Http\Middleware;

use App\Modules\Security\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModulePermission
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($user->userHasModulePermission($module, $activeCondominiumId)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'No tienes permiso para acceder a este módulo.',
        ], 403);
    }
}


