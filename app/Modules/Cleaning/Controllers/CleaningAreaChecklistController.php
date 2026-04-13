<?php

namespace App\Modules\Cleaning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cleaning\Models\CleaningArea;
use App\Modules\Cleaning\Models\CleaningAreaChecklist;
use App\Modules\Cleaning\Models\CleaningSchedule;
use App\Modules\Core\Models\Operative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CleaningAreaChecklistController extends Controller
{
    public function index(Request $request, int $areaId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($areaId, $activeCondominiumId);

        return response()->json(
            $area->checklistTemplateItems()
                ->with(['assignedUser:id,full_name,email', 'lastExecutedBy:id,full_name,email'])
                ->orderBy('id')
                ->get()
                ->map(fn (CleaningAreaChecklist $item) => $this->presentItem($item, $activeCondominiumId))
                ->values()
        );
    }

    public function store(Request $request, int $areaId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($areaId, $activeCondominiumId);
        $validated = $this->validateChecklistPayload($request, $activeCondominiumId, false);

        $item = $area->checklistTemplateItems()->create($this->buildChecklistPayload($validated));
        $schedule = $this->upsertLinkedSchedule($item, $activeCondominiumId, true);

        return response()->json($this->presentItem($item->fresh()->load(['assignedUser:id,full_name,email', 'lastExecutedBy:id,full_name,email']), $activeCondominiumId, $schedule), 201);
    }

    public function update(Request $request, int $areaId, int $itemId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($areaId, $activeCondominiumId);
        $item = CleaningAreaChecklist::query()
            ->where('cleaning_area_id', $area->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $validated = $this->validateChecklistPayload($request, $activeCondominiumId, true);
        $item->update($this->buildChecklistPayload($validated, true));
        $schedule = $this->upsertLinkedSchedule($item->fresh(), $activeCondominiumId, false, $validated);

        return response()->json($this->presentItem($item->fresh()->load(['assignedUser:id,full_name,email', 'lastExecutedBy:id,full_name,email']), $activeCondominiumId, $schedule));
    }

    public function destroy(Request $request, int $areaId, int $itemId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $area = $this->resolveCleaningAreaInActiveCondominium($areaId, $activeCondominiumId);

        $item = CleaningAreaChecklist::query()
            ->where('cleaning_area_id', $area->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $this->findLinkedSchedule($item->id, $activeCondominiumId)?->delete();
        $item->delete();

        return response()->json([
            'message' => 'Item de checklist eliminado.',
        ]);
    }

    private function validateChecklistPayload(Request $request, int $activeCondominiumId, bool $partial): array
    {
        $requiredRule = $partial ? ['sometimes'] : ['required'];

        $validated = $request->validate([
            'item_name' => [...$requiredRule, 'string', 'max:255'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'frequency_type' => [...$requiredRule, Rule::in([
                CleaningSchedule::FREQUENCY_DAILY,
                CleaningSchedule::FREQUENCY_WEEKLY,
                CleaningSchedule::FREQUENCY_MONTHLY,
                CleaningSchedule::FREQUENCY_CUSTOM,
            ])],
            'repeat_interval' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'days_of_week' => ['sometimes', 'nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:0', 'max:6'],
            'start_date' => [...$requiredRule, 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', Rule::in([
                CleaningAreaChecklist::STATUS_PENDING,
                CleaningAreaChecklist::STATUS_IN_PROGRESS,
                CleaningAreaChecklist::STATUS_COMPLETED,
            ])],
        ]);

        if (array_key_exists('assigned_user_id', $validated) && ! empty($validated['assigned_user_id'])) {
            $this->resolveAssignedUserInActiveCondominium((int) $validated['assigned_user_id'], $activeCondominiumId);
        }

        if (($validated['frequency_type'] ?? null) === CleaningSchedule::FREQUENCY_WEEKLY) {
            $days = $this->normalizeDaysOfWeek($validated['days_of_week'] ?? null);
            if (empty($days)) {
                throw ValidationException::withMessages([
                    'days_of_week' => ['Debes seleccionar al menos un dia para frecuencia semanal.'],
                ]);
            }
        }

        return $validated;
    }

    private function buildChecklistPayload(array $validated, bool $partial = false): array
    {
        $payload = [];
        foreach ([
            'item_name',
            'assigned_user_id',
            'frequency_type',
            'repeat_interval',
            'start_date',
            'end_date',
            'status',
        ] as $field) {
            if (! $partial || array_key_exists($field, $validated)) {
                if ($field === 'item_name' && array_key_exists('item_name', $validated)) {
                    $payload['item_name'] = trim((string) $validated['item_name']);
                } elseif (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }
        }

        if (! $partial && ! array_key_exists('repeat_interval', $payload)) {
            $payload['repeat_interval'] = 1;
        }

        if (! $partial && ! array_key_exists('status', $payload)) {
            $payload['status'] = CleaningAreaChecklist::STATUS_PENDING;
        }

        if (! $partial || array_key_exists('days_of_week', $validated)) {
            $payload['days_of_week'] = $this->normalizeDaysOfWeek($validated['days_of_week'] ?? null);
        }

        return $payload;
    }

    private function upsertLinkedSchedule(
        CleaningAreaChecklist $item,
        int $activeCondominiumId,
        bool $creating,
        array $validated = []
    ): ?CleaningSchedule {
        $schedule = $this->findLinkedSchedule((int) $item->id, $activeCondominiumId);

        $payload = [
            'condominium_id' => $activeCondominiumId,
            'cleaning_area_id' => (int) $item->cleaning_area_id,
            'name' => $item->item_name,
            'description' => '[checklist_item:'.$item->id.']',
            'assigned_user_id' => $item->assigned_user_id,
            'frequency_type' => $item->frequency_type ?: CleaningSchedule::FREQUENCY_WEEKLY,
            'repeat_interval' => (int) ($item->repeat_interval ?: 1),
            'days_of_week' => $this->normalizeDaysOfWeek($item->days_of_week),
            'start_date' => optional($item->start_date)->toDateString() ?: now()->toDateString(),
            'end_date' => optional($item->end_date)->toDateString(),
        ];

        if ($schedule) {
            $schedule->update($payload);
            return $schedule->fresh()->load(['assignedUser:id,full_name,email']);
        }

        if (! $creating && empty($validated)) {
            return null;
        }

        return CleaningSchedule::query()
            ->create([
                ...$payload,
                'is_active' => true,
            ])
            ->load(['assignedUser:id,full_name,email']);
    }

    private function presentItem(
        CleaningAreaChecklist $item,
        int $activeCondominiumId,
        ?CleaningSchedule $linkedSchedule = null
    ): array {
        $schedule = $linkedSchedule ?: $this->findLinkedSchedule((int) $item->id, $activeCondominiumId);

        return [
            'id' => $item->id,
            'cleaning_area_id' => $item->cleaning_area_id,
            'item_name' => $item->item_name,
            'assigned_user_id' => $item->assigned_user_id,
            'assigned_user' => $item->assignedUser ? [
                'id' => $item->assignedUser->id,
                'full_name' => $item->assignedUser->full_name,
                'email' => $item->assignedUser->email,
            ] : null,
            'frequency_type' => $item->frequency_type,
            'repeat_interval' => $item->repeat_interval,
            'days_of_week' => $this->normalizeDaysOfWeek($item->days_of_week),
            'start_date' => optional($item->start_date)->toDateString(),
            'end_date' => optional($item->end_date)->toDateString(),
            'status' => $item->status,
            'last_executed_at' => optional($item->last_executed_at)?->toISOString(),
            'last_executed_by' => $item->lastExecutedBy ? [
                'id' => $item->lastExecutedBy->id,
                'full_name' => $item->lastExecutedBy->full_name,
                'email' => $item->lastExecutedBy->email,
            ] : null,
            'linked_schedule' => $schedule ? [
                'id' => $schedule->id,
                'is_active' => (bool) $schedule->is_active,
            ] : null,
            'created_at' => optional($item->created_at)?->toISOString(),
            'updated_at' => optional($item->updated_at)?->toISOString(),
        ];
    }

    private function normalizeDaysOfWeek($days): ?array
    {
        if (! is_array($days) || empty($days)) {
            return null;
        }

        $normalized = collect($days)
            ->map(fn ($day) => (int) $day)
            ->filter(fn ($day) => $day >= 0 && $day <= 6)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return empty($normalized) ? null : $normalized;
    }

    private function findLinkedSchedule(int $itemId, int $activeCondominiumId): ?CleaningSchedule
    {
        return CleaningSchedule::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('description', '[checklist_item:'.$itemId.']')
            ->first();
    }

    private function resolveAssignedUserInActiveCondominium(int $userId, int $activeCondominiumId): void
    {
        $exists = Operative::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereHas('user.roles', function ($query) use ($activeCondominiumId) {
                $query->where('roles.name', 'Aseo')
                    ->where('roles.is_active', true)
                    ->where('user_role.condominium_id', $activeCondominiumId);
            })
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['El responsable no pertenece al condominio activo o no es un operativo activo.'],
            ]);
        }
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

    private function resolveCleaningAreaInActiveCondominium(int $areaId, int $activeCondominiumId): CleaningArea
    {
        $area = CleaningArea::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $areaId)
            ->first();

        if (! $area) {
            throw ValidationException::withMessages([
                'cleaning_area_id' => ['El area de aseo no pertenece al condominio activo.'],
            ]);
        }

        return $area;
    }
}
