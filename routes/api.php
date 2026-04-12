<?php

use App\Modules\Security\Controllers\AuthController;
use App\Modules\Core\Controllers\ApartmentController;
use App\Modules\Core\Controllers\CondominiumController;
use App\Modules\Core\Controllers\CorrespondenceController;
use App\Modules\Cleaning\Controllers\CleaningAreaController;
use App\Modules\Cleaning\Controllers\CleaningAreaChecklistController;
use App\Modules\Cleaning\Controllers\CleaningChecklistItemController;
use App\Modules\Cleaning\Controllers\CleaningRecordController;
use App\Modules\Cleaning\Controllers\CleaningScheduleController;
use App\Modules\Core\Controllers\DashboardController;
use App\Modules\Core\Controllers\EmployeeEntryController;
use App\Modules\Emergencies\Controllers\EmergencyContactController;
use App\Modules\Emergencies\Controllers\EmergencyTypeController;
use App\Modules\Emergencies\Controllers\HealthIncidentController;
use App\Modules\Core\Controllers\OperativeController;
use App\Modules\Core\Controllers\ReportController;
use App\Modules\Residents\Controllers\ResidentController;
use App\Modules\Core\Controllers\UnitTypeController;
use App\Modules\Security\Controllers\UserController;
use App\Modules\Visits\Controllers\VisitController;
use App\Modules\Vehicles\Controllers\VehicleController;
use App\Modules\Vehicles\Controllers\VehicleEntryController;
use App\Modules\Vehicles\Controllers\VehicleIncidentController;
use App\Modules\Vehicles\Controllers\VehicleTypeController;
use App\Modules\Inventory\Controllers\InventoryCategoryController;
use App\Modules\Inventory\Controllers\InventoryController;
use App\Modules\Inventory\Controllers\InventoryMovementController;
use App\Modules\Inventory\Controllers\ProductController;
use App\Modules\Providers\Controllers\SupplierController;
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
    Route::patch('/users/{id}/change-password', [UserController::class, 'changePassword']);
    Route::get('/users/{id}/module-permissions', [UserController::class, 'getModulePermissions']);
    Route::post('/users/{id}/module-permissions', [UserController::class, 'saveModulePermissions']);
});

Route::middleware(['auth:api', 'resolve.active.condominium'])->group(function () {
    Route::get('/condominiums/active', [CondominiumController::class, 'active']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/reports/daily-log', [ReportController::class, 'dailyLog'])->middleware('module:settings');
    Route::get('/reports/monthly-summary', [ReportController::class, 'monthlySummary'])->middleware('module:settings');

    Route::middleware('module:settings')->group(function () {
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

        Route::get('/vehicle-types', [VehicleTypeController::class, 'index']);
        Route::post('/vehicle-types', [VehicleTypeController::class, 'store']);
        Route::put('/vehicle-types/{id}', [VehicleTypeController::class, 'update']);
        Route::patch('/vehicle-types/{id}/toggle', [VehicleTypeController::class, 'toggle']);
    });

    Route::middleware('module:visits')->group(function () {
        Route::get('/visits/bootstrap-data', [VisitController::class, 'bootstrapData']);
        Route::get('/visits', [VisitController::class, 'index']);
        Route::post('/visits', [VisitController::class, 'store']);
        Route::patch('/visits/{id}/checkout', [VisitController::class, 'checkout']);
    });

    Route::middleware('module:vehicles')->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index']);
        Route::get('/vehicles/bootstrap-data', [VehicleController::class, 'bootstrapData']);
        Route::post('/vehicles', [VehicleController::class, 'store']);
        Route::put('/vehicles/{id}', [VehicleController::class, 'update']);
        Route::patch('/vehicles/{id}/toggle', [VehicleController::class, 'toggle']);

        Route::get('/vehicle-entries', [VehicleEntryController::class, 'index']);
        Route::post('/vehicle-entries', [VehicleEntryController::class, 'store']);
        Route::patch('/vehicle-entries/{id}/checkout', [VehicleEntryController::class, 'checkout']);
    });

    Route::prefix('employee-entries')->middleware('module:employee-entries')->group(function () {
        Route::get('/bootstrap-data', [EmployeeEntryController::class, 'bootstrapData']);
        Route::get('/', [EmployeeEntryController::class, 'index']);
        Route::post('/', [EmployeeEntryController::class, 'store']);
        Route::put('/checkout/{id}', [EmployeeEntryController::class, 'checkout']);
        Route::put('/cancel/{id}', [EmployeeEntryController::class, 'cancel']);
    });

    Route::middleware('module:vehicle-incidents')->group(function () {
        Route::get('/vehicle-incidents', [VehicleIncidentController::class, 'index']);
        Route::post('/vehicle-incidents', [VehicleIncidentController::class, 'store']);
        Route::patch('/vehicle-incidents/{id}/resolve', [VehicleIncidentController::class, 'resolve']);
    });

    Route::middleware('module:emergencies')->group(function () {
        Route::get('/areas', [HealthIncidentController::class, 'areas']);
        Route::get('/emergency-types', [EmergencyTypeController::class, 'index']);
        Route::post('/emergency-types', [EmergencyTypeController::class, 'store']);
        Route::put('/emergency-types/{id}', [EmergencyTypeController::class, 'update']);
        Route::patch('/emergency-types/{id}/toggle', [EmergencyTypeController::class, 'toggle']);

        Route::prefix('emergency-contacts')->group(function () {
            Route::get('/', [EmergencyContactController::class, 'index']);
            Route::post('/', [EmergencyContactController::class, 'store']);
            Route::put('/{id}', [EmergencyContactController::class, 'update']);
            Route::patch('/{id}/toggle', [EmergencyContactController::class, 'toggle']);
        });

        Route::get('/emergencies', [HealthIncidentController::class, 'index']);
        Route::post('/emergencies', [HealthIncidentController::class, 'store']);
        Route::patch('/emergencies/{id}/progress', [HealthIncidentController::class, 'progress']);
        Route::patch('/emergencies/{id}/close', [HealthIncidentController::class, 'close']);
    });

    Route::middleware('module:correspondences')->group(function () {
        Route::get('/correspondences/bootstrap-data', [CorrespondenceController::class, 'bootstrapData']);
        Route::get('/correspondences', [CorrespondenceController::class, 'index']);
        Route::post('/correspondences', [CorrespondenceController::class, 'store']);
        Route::patch('/correspondences/{id}/deliver', [CorrespondenceController::class, 'deliver']);
    });

    Route::middleware('module:cleaning')->group(function () {
        Route::get('/cleaning/bootstrap-data', [CleaningRecordController::class, 'bootstrapData']);
        Route::get('/cleaning-areas', [CleaningAreaController::class, 'index']);
        Route::post('/cleaning-areas', [CleaningAreaController::class, 'store']);
        Route::put('/cleaning-areas/{id}', [CleaningAreaController::class, 'update']);
        Route::delete('/cleaning-areas/{id}', [CleaningAreaController::class, 'destroy']);
        Route::patch('/cleaning-areas/{id}/toggle', [CleaningAreaController::class, 'toggle']);

        // Checklist template por area (nuevas rutas)
        Route::get('/cleaning-areas/{areaId}/checklists', [CleaningAreaChecklistController::class, 'index']);
        Route::post('/cleaning-areas/{areaId}/checklists', [CleaningAreaChecklistController::class, 'store']);
        // Compatibilidad con frontend actual
        Route::get('/cleaning-areas/{areaId}/checklist', [CleaningAreaChecklistController::class, 'index']);
        Route::post('/cleaning-areas/{areaId}/checklist', [CleaningAreaChecklistController::class, 'store']);
        Route::delete('/cleaning-areas/{areaId}/checklist/{itemId}', [CleaningAreaChecklistController::class, 'destroy']);

        Route::get('/cleaning-records', [CleaningRecordController::class, 'index']);
        Route::get('/cleaning-records/{id}/checklist', [CleaningRecordController::class, 'checklist']);
        Route::post('/cleaning-records', [CleaningRecordController::class, 'store']);
        Route::get('/cleaning-records/{id}', [CleaningRecordController::class, 'show']);
        Route::put('/cleaning-records/{id}', [CleaningRecordController::class, 'update']);
        Route::delete('/cleaning-records/{id}', [CleaningRecordController::class, 'destroy']);
        Route::patch('/cleaning-records/{id}/complete', [CleaningRecordController::class, 'complete']);

        Route::get('/cleaning-schedules', [CleaningScheduleController::class, 'index']);
        Route::post('/cleaning-schedules', [CleaningScheduleController::class, 'store']);
        Route::put('/cleaning-schedules/{id}', [CleaningScheduleController::class, 'update']);
        Route::delete('/cleaning-schedules/{id}', [CleaningScheduleController::class, 'destroy']);

        // Checklist items por registro de limpieza
        Route::get('/checklists/{recordId}/items', [CleaningChecklistItemController::class, 'indexByRecord']);
        Route::post('/checklists/{recordId}/items', [CleaningChecklistItemController::class, 'storeByRecord']);
        Route::put('/items/{id}', [CleaningChecklistItemController::class, 'update']);
        Route::delete('/items/{id}', [CleaningChecklistItemController::class, 'destroy']);
        // Compatibilidad con frontend actual
        Route::post('/cleaning-records/{recordId}/checklist-items', [CleaningChecklistItemController::class, 'storeByRecord']);
        Route::patch('/cleaning-records/{recordId}/checklist-items/{itemId}', [CleaningChecklistItemController::class, 'updateByRecord']);
    });

    Route::middleware('module:inventory')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->middleware('inventory.operation');
        Route::get('/inventory/products-with-movements', [ProductController::class, 'productsWithMovements'])->middleware('inventory.operation');
        Route::post('/products', [ProductController::class, 'store'])->middleware('inventory.settings');
        Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('inventory.settings');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('inventory.settings');
        Route::get('/inventories', [InventoryController::class, 'index'])->middleware('inventory.operation');
        Route::post('/inventories', [InventoryController::class, 'store'])->middleware('inventory.settings');
        Route::put('/inventories/{id}', [InventoryController::class, 'update'])->middleware('inventory.settings');
        Route::patch('/inventories/{id}/toggle', [InventoryController::class, 'toggle'])->middleware('inventory.settings');

        Route::get('/inventory-categories', [InventoryCategoryController::class, 'index'])->middleware('inventory.operation');
        Route::post('/inventory-categories', [InventoryCategoryController::class, 'store'])->middleware('inventory.settings');
        Route::put('/inventory-categories/{id}', [InventoryCategoryController::class, 'update'])->middleware('inventory.settings');
        Route::patch('/inventory-categories/{id}/toggle', [InventoryCategoryController::class, 'toggle'])->middleware('inventory.settings');

        Route::get('/inventory/low-stock', [ProductController::class, 'lowStock'])->middleware('inventory.operation');
        Route::post('/inventory/activos/{id}/baja', [ProductController::class, 'deactivateAsset'])->middleware('inventory.settings');
        Route::post('/inventory/assets/{id}/deactivate', [ProductController::class, 'deactivateAsset'])->middleware('inventory.settings');

        Route::get('/inventory/movements', [InventoryMovementController::class, 'index'])->middleware('inventory.operation');
        Route::post('/inventory-movements/entry', [InventoryMovementController::class, 'entry'])->middleware('inventory.operation');
        Route::post('/inventory-movements/exit', [InventoryMovementController::class, 'exit'])->middleware('inventory.operation');
        Route::get('/products/{id}/movements', [InventoryMovementController::class, 'historyByProduct'])->middleware('inventory.operation');

        Route::middleware(['inventory.settings'])->group(function () {
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::post('/suppliers', [SupplierController::class, 'store']);
            Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
            Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);
        });
    });
});


























