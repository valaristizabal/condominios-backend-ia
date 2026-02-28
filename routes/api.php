<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Core\CondominiumController;
use App\Http\Controllers\Core\DashboardController;
use App\Http\Controllers\Core\OperativeController;
use App\Http\Controllers\Core\ResidentController;
use App\Http\Controllers\Core\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::middleware(['auth:api', 'super_admin'])->group(function () {
    Route::get('/condominiums', [CondominiumController::class, 'index']);
    Route::post('/condominiums', [CondominiumController::class, 'store']);
    Route::put('/condominiums/{id}', [CondominiumController::class, 'update']);
    Route::patch('/condominiums/{id}/toggle', [CondominiumController::class, 'toggle']);
});

Route::middleware(['auth:api', 'manage.users', 'resolve.active.condominium'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
});

Route::middleware(['auth:api', 'resolve.active.condominium'])->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('/operatives/roles', [OperativeController::class, 'roles']);
    Route::get('/operatives', [OperativeController::class, 'index']);
    Route::post('/operatives', [OperativeController::class, 'store']);
    Route::put('/operatives/{id}', [OperativeController::class, 'update']);

    Route::get('/residents', [ResidentController::class, 'index']);
    Route::post('/residents', [ResidentController::class, 'store']);
    Route::put('/residents/{id}', [ResidentController::class, 'update']);
});
