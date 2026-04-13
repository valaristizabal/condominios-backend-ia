<?php

namespace App\Modules\Cleaning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cleaning\Models\CleaningArea;
use App\Modules\Cleaning\Models\CleaningAreaChecklist;
use App\Modules\Cleaning\Models\CleaningChecklistItem;
use App\Modules\Cleaning\Models\CleaningRecord;
use App\Modules\Cleaning\Models\CleaningSchedule;
use App\Modules\Core\Models\Operative;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CleaningRecordController extends Controller
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_COMPLETED = 'completed';


    public function bootstrapData(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $areas = CleaningArea::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderByDesc('id')
            ->get();

        $operatives = Operative::query()
            ->with(['user:id,full_name,email,document_number'])
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->whereHas('user.roles', function ($query) use ($activeCondominiumId) {
                $query->where('roles.name', 'Aseo')
                    ->where('roles.is_active', true)
                    ->where('user_role.condominium_id', $activeCondominiumId);
            })
            ->orderBy('position')
            ->orderByDesc('id')
            ->get([
                'id',
                'user_id',
                'condominium_id',
                'position',
                'contract_type',
                'is_active',
            ]);

        $records = CleaningRecord::query()
            ->with([
                'cleaningArea:id,condominium_id,name,description,is_active',
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('cleaning_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'areas' => $areas,
            'operatives' => $operatives,
            'records' => $records,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([self::STATUS_PENDING, self::STATUS_COMPLETED])],
            'cleaning_area_id' => ['nullable', 'integer'],
            'operative_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CleaningRecord::query()
            ->with([
                'cleaningArea:id,condominium_id,name,description,is_active',
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('cleaning_date')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['cleaning_area_id'])) {
            $query->where('cleaning_area_id', (int) $validated['cleaning_area_id']);
        }

        if (! empty($validated['operative_id'])) {
            $query->where('operative_id', (int) $validated['operative_id']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('cleaning_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('cleaning_date', '<=', $validated['date_to']);
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 20)));
    }

    public function checklist(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($id, $activeCondominiumId);

        $items = CleaningChecklistItem::query()
            ->where('cleaning_record_id', $record->id)
            ->orderBy('id')
            ->get([
                'id',
                'cleaning_record_id',
                'item_name',
                'completed',
                'created_at',
                'updated_at',
            ]);

        return response()->json($items);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($id, $activeCondominiumId);

        return response()->json(
            $record->load([
                'cleaningArea:id,condominium_id,name,description,is_active',
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
                'checklistItems:id,cleaning_record_id,item_name,completed',
            ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectForbiddenManagedFields($request);

        $validated = $request->validate([
            'cleaning_area_id' => ['required', 'integer', 'exists:cleaning_areas,id'],
            'operative_id' => ['required', 'integer', 'exists:operatives,id'],
            'cleaning_date' => ['required', 'date'],
            'observations' => ['nullable', 'string'],
        ]);

        $area = $this->resolveCleaningAreaInActiveCondominium((int) $validated['cleaning_area_id'], $activeCondominiumId);
        $operative = $this->resolveOperativeInActiveCondominium((int) $validated['operative_id'], $activeCondominiumId);

        $scheduledChecklistItemIds = $this->resolveScheduledChecklistItemIdsForDate(
            $activeCondominiumId,
            (int) $area->id,
            (string) $validated['cleaning_date'],
            (int) ($operative->user_id ?? 0)
        );

        $templateItems = $area->checklistTemplateItems()
            ->whereIn('id', $scheduledChecklistItemIds)
            ->orderBy('id')
            ->get(['id', 'assigned_user_id', 'item_name']);

        if ($templateItems->isEmpty()) {
            return response()->json([
                'message' => 'Esta área no tiene tareas programadas para este día.',
            ], 422);
        }

        $completedSourceChecklistItemIds = $this->resolveCompletedSourceChecklistItemIdsForDate(
            $activeCondominiumId,
            (int) $area->id,
            (string) $validated['cleaning_date']
        );

        $alreadyExists = CleaningRecord::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('cleaning_area_id', $area->id)
            ->where('operative_id', $operative->id)
            ->whereDate('cleaning_date', $validated['cleaning_date'])
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'operative_id' => ['Este operario ya tiene un registro de limpieza para esta area en esta fecha.'],
            ]);
        }
        $record = DB::transaction(function () use ($request, $validated, $activeCondominiumId, $area, $operative, $templateItems, $completedSourceChecklistItemIds) {
            $newRecord = CleaningRecord::query()->create([
                'condominium_id' => $activeCondominiumId,
                'cleaning_area_id' => $area->id,
                'operative_id' => $operative->id,
                'registered_by_id' => $request->user()?->id,
                'cleaning_date' => $validated['cleaning_date'],
                'started_at' => now(),
                'status' => self::STATUS_PENDING,
                'observations' => $validated['observations'] ?? null,
            ]);

            if ($templateItems->isNotEmpty()) {
                $now = now();
                $payload = $templateItems->map(fn ($item) => [
                    'cleaning_record_id' => $newRecord->id,
                    'source_checklist_item_id' => $item->id,
                    'item_name' => $item->item_name,
                    'completed' => in_array((int) $item->id, $completedSourceChecklistItemIds, true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                CleaningChecklistItem::query()->insert($payload);
            }

            CleaningAreaChecklist::query()
                ->whereIn('id', $templateItems->pluck('id')->all())
                ->update([
                    'status' => CleaningAreaChecklist::STATUS_IN_PROGRESS,
                ]);

            return $newRecord;
        });

        return response()->json(
            $record->fresh()->load([
                'cleaningArea:id,condominium_id,name,description,is_active',
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
                'checklistItems:id,cleaning_record_id,item_name,completed',
            ]),
            201
        );
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectForbiddenManagedFields($request);

        $validated = $request->validate([
            'observations' => ['required', 'string', 'min:3'],
        ]);

        $record = $this->resolveCleaningRecordInActiveCondominium($id, $activeCondominiumId);

        if ($record->status === self::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'El registro de aseo ya esta completado.',
            ], 400);
        }

        $hasChecklistItems = CleaningChecklistItem::query()
            ->where('cleaning_record_id', $record->id)
            ->exists();

        if (! $hasChecklistItems) {
            throw ValidationException::withMessages([
                'checklist' => ['Debe existir al menos un item para completar el registro de aseo.'],
            ]);
        }

        $hasPendingItems = CleaningChecklistItem::query()
            ->where('cleaning_record_id', $record->id)
            ->where('completed', false)
            ->exists();

        if ($hasPendingItems) {
            throw ValidationException::withMessages([
                'checklist' => ['Debes completar todos los items antes de finalizar la limpieza.'],
            ]);
        }

        $record->update([
            'observations' => $validated['observations'],
            'status' => self::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);

        $sourceChecklistItemIds = CleaningChecklistItem::query()
            ->where('cleaning_record_id', $record->id)
            ->whereNotNull('source_checklist_item_id')
            ->pluck('source_checklist_item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($sourceChecklistItemIds)) {
            $executedByUserId = $record->operative?->user_id ?: $request->user()?->id;
            CleaningAreaChecklist::query()
                ->whereIn('id', $sourceChecklistItemIds)
                ->update([
                    'status' => CleaningAreaChecklist::STATUS_COMPLETED,
                    'last_executed_by_id' => $executedByUserId,
                    'last_executed_at' => now(),
                ]);
        }

        return response()->json(
            $record->fresh()->load([
                'cleaningArea:id,condominium_id,name,description,is_active',
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
                'checklistItems:id,cleaning_record_id,item_name,completed',
            ])
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectForbiddenManagedFields($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($id, $activeCondominiumId);

        $validated = $request->validate([
            'cleaning_date' => ['sometimes', 'date'],
            'observations' => ['sometimes', 'nullable', 'string'],
            'cleaning_area_id' => ['sometimes', 'integer', 'exists:cleaning_areas,id'],
            'operative_id' => ['sometimes', 'integer', 'exists:operatives,id'],
        ]);

        if ($record->status === self::STATUS_COMPLETED) {
            if (array_key_exists('cleaning_area_id', $validated) || array_key_exists('operative_id', $validated)) {
                return response()->json([
                    'message' => 'No se puede cambiar area u operativo en un registro completado.',
                ], 400);
            }
        }

        if (array_key_exists('cleaning_area_id', $validated)) {
            $this->resolveCleaningAreaInActiveCondominium((int) $validated['cleaning_area_id'], $activeCondominiumId);
        }

        if (array_key_exists('operative_id', $validated)) {
            $this->resolveOperativeInActiveCondominium((int) $validated['operative_id'], $activeCondominiumId);
        }

        $record->update($validated);

        return response()->json(
            $record->fresh()->load([
                'cleaningArea:id,condominium_id,name,description,is_active',
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
                'checklistItems:id,cleaning_record_id,item_name,completed',
            ])
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($id, $activeCondominiumId);

        if ($record->status === self::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'No se puede eliminar un registro completado.',
            ], 400);
        }

        $record->delete();

        return response()->json([
            'message' => 'Registro de aseo eliminado.',
        ]);
    }

    private function resolveActiveCondominiumId(Request $request): int
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

    private function rejectForbiddenManagedFields(Request $request): void
    {
        if ($request->query->has('status') || $request->request->has('status')) {
            throw ValidationException::withMessages([
                'status' => ['No se permite enviar status en este endpoint.'],
            ]);
        }
    }

    private function resolveCleaningAreaInActiveCondominium(int $cleaningAreaId, int $activeCondominiumId): CleaningArea
    {
        $area = CleaningArea::query()
            ->where('id', $cleaningAreaId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $area) {
            throw ValidationException::withMessages([
                'cleaning_area_id' => ['El area de aseo no pertenece al condominio activo.'],
            ]);
        }

        return $area;
    }

    private function resolveOperativeInActiveCondominium(int $operativeId, int $activeCondominiumId): Operative
    {
        $operative = Operative::query()
            ->where('id', $operativeId)
            ->where('condominium_id', $activeCondominiumId)
            ->whereHas('user.roles', function ($query) use ($activeCondominiumId) {
                $query->where('roles.name', 'Aseo')
                    ->where('roles.is_active', true)
                    ->where('user_role.condominium_id', $activeCondominiumId);
            })
            ->first();

        if (! $operative) {
            throw ValidationException::withMessages([
                'operative_id' => ['El operativo no pertenece al condominio activo.'],
            ]);
        }

        return $operative;
    }

    private function resolveCleaningRecordInActiveCondominium(int $id, int $activeCondominiumId): CleaningRecord
    {
        $record = CleaningRecord::query()
            ->where('id', $id)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'cleaning_record_id' => ['El registro de aseo no pertenece al condominio activo.'],
            ]);
        }

        return $record;
    }

    private function resolveScheduledChecklistItemIdsForDate(
        int $activeCondominiumId,
        int $cleaningAreaId,
        string $cleaningDate,
        ?int $assignedUserId = null
    ): array {
        $date = CarbonImmutable::parse($cleaningDate)->startOfDay();

        $schedules = CleaningSchedule::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('cleaning_area_id', $cleaningAreaId)
            ->when(
                ! empty($assignedUserId),
                fn ($query) => $query->where(function ($subQuery) use ($assignedUserId) {
                    $subQuery->whereNull('assigned_user_id')
                        ->orWhere('assigned_user_id', $assignedUserId);
                })
            )
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $date->toDateString());
            })
            ->get([
                'id',
                'name',
                'description',
                'frequency_type',
                'repeat_interval',
                'days_of_week',
                'start_date',
                'end_date',
            ]);

        $pattern = '/\[checklist_item:(\d+)\]/';

        $activeSchedules = $schedules
            ->filter(fn (CleaningSchedule $schedule) => $this->scheduleRunsOnDate($schedule, $date))
            ->values();

        $checklistIds = $activeSchedules
            ->map(function (CleaningSchedule $schedule) use ($pattern) {
                preg_match($pattern, (string) ($schedule->description ?? ''), $matches);
                return isset($matches[1]) ? (int) $matches[1] : null;
            })
            ->filter(fn (?int $checklistItemId) => is_int($checklistItemId) && $checklistItemId > 0)
            ->unique()
            ->values()
            ->all();

        if (! empty($checklistIds)) {
            return $checklistIds;
        }

        if ($activeSchedules->isEmpty()) {
            return [];
        }

        $scheduleNames = $activeSchedules
            ->map(fn (CleaningSchedule $schedule) => trim((string) $schedule->name))
            ->filter()
            ->values();

        if ($scheduleNames->isEmpty()) {
            return [];
        }

        return CleaningArea::query()
            ->where('id', $cleaningAreaId)
            ->first()
            ?->checklistTemplateItems()
            ->whereIn('item_name', $scheduleNames->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all() ?? [];
    }

    private function resolveCompletedSourceChecklistItemIdsForDate(
        int $activeCondominiumId,
        int $cleaningAreaId,
        string $cleaningDate
    ): array {
        return CleaningChecklistItem::query()
            ->join('cleaning_records', 'cleaning_records.id', '=', 'cleaning_checklist_items.cleaning_record_id')
            ->where('cleaning_records.condominium_id', $activeCondominiumId)
            ->where('cleaning_records.cleaning_area_id', $cleaningAreaId)
            ->whereDate('cleaning_records.cleaning_date', $cleaningDate)
            ->where('cleaning_checklist_items.completed', true)
            ->whereNotNull('cleaning_checklist_items.source_checklist_item_id')
            ->pluck('cleaning_checklist_items.source_checklist_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function scheduleRunsOnDate(CleaningSchedule $schedule, CarbonImmutable $date): bool
    {
        $startDate = CarbonImmutable::parse((string) $schedule->start_date)->startOfDay();

        if ($date->lt($startDate)) {
            return false;
        }

        if ($schedule->end_date) {
            $endDate = CarbonImmutable::parse((string) $schedule->end_date)->endOfDay();
            if ($date->gt($endDate)) {
                return false;
            }
        }

        $interval = max(1, (int) $schedule->repeat_interval);

        return match ((string) $schedule->frequency_type) {
            CleaningSchedule::FREQUENCY_DAILY => $startDate->diffInDays($date) % $interval === 0,
            CleaningSchedule::FREQUENCY_CUSTOM => $startDate->diffInDays($date) % $interval === 0,
            CleaningSchedule::FREQUENCY_WEEKLY => $this->matchesWeeklyRule($schedule, $date, $startDate, $interval),
            CleaningSchedule::FREQUENCY_MONTHLY => $this->matchesMonthlyRule($date, $startDate, $interval),
            default => false,
        };
    }

    private function matchesWeeklyRule(
        CleaningSchedule $schedule,
        CarbonImmutable $date,
        CarbonImmutable $startDate,
        int $interval
    ): bool {
        $days = collect($schedule->days_of_week ?? [])
            ->map(fn ($day) => (int) $day)
            ->filter(fn ($day) => $day >= 0 && $day <= 6)
            ->unique()
            ->values()
            ->all();

        if (empty($days)) {
            return false;
        }

        if (! in_array($date->dayOfWeek, $days, true)) {
            return false;
        }

        $weeksBetween = intdiv($startDate->diffInDays($date), 7);

        return $weeksBetween % $interval === 0;
    }

    private function matchesMonthlyRule(CarbonImmutable $date, CarbonImmutable $startDate, int $interval): bool
    {
        if ($date->day !== $startDate->day) {
            return false;
        }

        $monthsBetween = $startDate->diffInMonths($date);

        return $monthsBetween % $interval === 0;
    }
}



