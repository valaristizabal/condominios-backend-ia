<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cleaning\Models\CleaningRecord;
use App\Modules\Core\Models\Correspondence;
use App\Modules\Core\Models\EmployeeEntry;
use App\Modules\Emergencies\Models\HealthIncident;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Vehicles\Models\VehicleEntry;
use App\Modules\Visits\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function dailyLog(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = (string) ($validated['date'] ?? now()->toDateString());

        $visits = Visit::query()
            ->with(['apartment.unitType', 'registeredBy'])
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('created_at', $date)
            ->orderByDesc('id')
            ->get();

        $employeeEntries = EmployeeEntry::query()
            ->with(['operative.user', 'registeredBy'])
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('check_in_at', $date)
            ->orderByDesc('id')
            ->get();

        $vehicleEntries = VehicleEntry::query()
            ->with(['vehicle', 'registeredBy'])
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('check_in_at', $date)
            ->orderByDesc('id')
            ->get();

        $correspondences = Correspondence::query()
            ->with(['apartment.unitType', 'receivedBy', 'deliveredBy'])
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('created_at', $date)
            ->orderByDesc('id')
            ->get();

        $cleaningRecords = CleaningRecord::query()
            ->with(['cleaningArea', 'operative.user', 'registeredBy'])
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('cleaning_date', $date)
            ->orderByDesc('id')
            ->get();

        $inventoryMovements = InventoryMovement::query()
            ->whereHas('product.inventory', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->whereDate('movement_date', $date)
            ->orderByDesc('id')
            ->get();

        $emergencies = HealthIncident::query()
            ->with(['emergencyType'])
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('event_date', $date)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'date' => $date,
            'visits' => $visits,
            'employee_entries' => $employeeEntries,
            'vehicle_entries' => $vehicleEntries,
            'correspondences' => $correspondences,
            'cleaning_records' => $cleaningRecords,
            'inventory_movements' => $inventoryMovements,
            'emergencies' => $emergencies,
        ]);
    }

    public function monthlySummary(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = (string) ($validated['month'] ?? now()->format('Y-m'));
        [$year, $monthNumber] = array_map('intval', explode('-', $month));

        $visitsTotal = Visit::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $monthNumber)
            ->count();

        $employeeEntriesTotal = EmployeeEntry::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereYear('check_in_at', $year)
            ->whereMonth('check_in_at', $monthNumber)
            ->count();

        $vehicleEntriesTotal = VehicleEntry::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereYear('check_in_at', $year)
            ->whereMonth('check_in_at', $monthNumber)
            ->count();

        $correspondencesTotal = Correspondence::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $monthNumber)
            ->count();

        $cleaningRecordsTotal = CleaningRecord::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereYear('cleaning_date', $year)
            ->whereMonth('cleaning_date', $monthNumber)
            ->count();

        $inventoryMovementsTotal = InventoryMovement::query()
            ->whereHas('product.inventory', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->whereYear('movement_date', $year)
            ->whereMonth('movement_date', $monthNumber)
            ->count();

        $emergenciesTotal = HealthIncident::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereYear('event_date', $year)
            ->whereMonth('event_date', $monthNumber)
            ->count();

        return response()->json([
            'month' => $month,
            'visits_total' => $visitsTotal,
            'employee_entries_total' => $employeeEntriesTotal,
            'vehicle_entries_total' => $vehicleEntriesTotal,
            'correspondences_total' => $correspondencesTotal,
            'cleaning_records_total' => $cleaningRecordsTotal,
            'inventory_movements_total' => $inventoryMovementsTotal,
            'emergencies_total' => $emergenciesTotal,
        ]);
    }

    private function activeCondominium(Request $request): int
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');

        if ($activeCondominiumId <= 0) {
            throw ValidationException::withMessages([
                'condominium' => ['No hay condominio activo resuelto para esta operacion.'],
            ]);
        }

        return $activeCondominiumId;
    }

    private function rejectCondominiumIdFromRequest(Request $request): void
    {
        if ($request->query->has('condominium_id') || $request->request->has('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }
}









