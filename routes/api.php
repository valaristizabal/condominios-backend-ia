<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Core\ApartmentController;
use App\Http\Controllers\Core\CondominiumController;
use App\Http\Controllers\Core\CorrespondenceController;
use App\Http\Controllers\Core\DashboardController;
use App\Http\Controllers\Core\OperativeController;
use App\Http\Controllers\Core\ResidentController;
use App\Http\Controllers\Core\UnitTypeController;
use App\Http\Controllers\Core\UserController;
use App\Http\Controllers\Core\VisitController;
use App\Http\Controllers\Core\VehicleController;
use App\Http\Controllers\Core\VehicleEntryController;
use App\Http\Controllers\Core\VehicleIncidentController;
use App\Http\Controllers\Core\VehicleTypeController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::middleware(['auth:api', 'super_usuario'])->group(function () {
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

    Route::get('/unit-types', [UnitTypeController::class, 'index']);
    Route::post('/unit-types', [UnitTypeController::class, 'store']);
    Route::put('/unit-types/{id}', [UnitTypeController::class, 'update']);
    Route::patch('/unit-types/{id}/toggle', [UnitTypeController::class, 'toggle']);

    Route::get('/apartments', [ApartmentController::class, 'index']);
    Route::post('/apartments', [ApartmentController::class, 'store']);
    Route::put('/apartments/{id}', [ApartmentController::class, 'update']);
    Route::patch('/apartments/{id}/toggle', [ApartmentController::class, 'toggle']);

    Route::get('/visits', [VisitController::class, 'index']);
    Route::post('/visits', [VisitController::class, 'store']);
    Route::patch('/visits/{id}/checkout', [VisitController::class, 'checkout']);

    Route::get('/vehicle-types', [VehicleTypeController::class, 'index']);
    Route::post('/vehicle-types', [VehicleTypeController::class, 'store']);
    Route::put('/vehicle-types/{id}', [VehicleTypeController::class, 'update']);
    Route::patch('/vehicle-types/{id}/toggle', [VehicleTypeController::class, 'toggle']);

    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::post('/vehicles', [VehicleController::class, 'store']);
    Route::put('/vehicles/{id}', [VehicleController::class, 'update']);
    Route::patch('/vehicles/{id}/toggle', [VehicleController::class, 'toggle']);

    Route::get('/vehicle-entries', [VehicleEntryController::class, 'index']);
    Route::post('/vehicle-entries', [VehicleEntryController::class, 'store']);
    Route::patch('/vehicle-entries/{id}/checkout', [VehicleEntryController::class, 'checkout']);

    Route::get('/vehicle-incidents', [VehicleIncidentController::class, 'index']);
    Route::post('/vehicle-incidents', [VehicleIncidentController::class, 'store']);
    Route::patch('/vehicle-incidents/{id}/resolve', [VehicleIncidentController::class, 'resolve']);

    Route::get('/correspondences', [CorrespondenceController::class, 'index']);
    Route::post('/correspondences', [CorrespondenceController::class, 'store']);
    Route::patch('/correspondences/{id}/deliver', [CorrespondenceController::class, 'deliver']);
});
