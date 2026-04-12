<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureInventoryOperationAccess;
use App\Http\Middleware\EnsureInventorySettingsAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserManagementAccess;
use App\Http\Middleware\CheckModulePermission;
use App\Http\Middleware\ResolveActiveCondominiumFromUserRole;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super_usuario' => EnsureSuperAdmin::class,
            'manage.users' => EnsureUserManagementAccess::class,
            'resolve.active.condominium' => ResolveActiveCondominiumFromUserRole::class,
            'inventory.operation' => EnsureInventoryOperationAccess::class,
            'inventory.settings' => EnsureInventorySettingsAccess::class,
            'module' => CheckModulePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();

