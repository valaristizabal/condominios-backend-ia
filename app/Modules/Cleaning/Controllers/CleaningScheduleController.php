<?php

namespace App\Modules\Cleaning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cleaning\Models\CleaningArea;
use App\Modules\Cleaning\Models\CleaningSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CleaningScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'cleaning_area_id' => ['nullable', 'integer'],
            'frequency_type' => ['nullable', Rule::in($this->allowedFrequencyTypes())],
        ]);

        $query = CleaningSchedule::query()
            ->with(['cleaningArea:id,condominium_id,name,is_active'])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('id');

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        if (! empty($validated['cleaning_area_id'])) {
            $query->where('cleaning_area_id', (int) $validated['cleaning_area_id']);
        }

        if (! empty($validated['frequency_type'])) {
            $query->where('frequency_type', $validated['frequency_type']);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $this->validateSchedulePayload($request, $activeCondominiumId);

        $schedule = CleaningSchedule::query()->create([
            'condominium_id' => $activeCondominiumId,
            'cleaning_area_id' => (int) $validated['cleaning_area_id'],
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'frequency_type' => $validated['frequency_type'],
            'repeat_interval' => (int) ($validated['repeat_interval'] ?? 1),
            'days_of_week' => $this->normalizeDaysOfWeek($validated['days_of_week'] ?? null),
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json(
            $schedule->fresh()->load(['cleaningArea:id,condominium_id,name,is_active']),
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $schedule = CleaningSchedule::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $this->validateSchedulePayload($request, $activeCondominiumId, true);

        $payload = [];
        foreach ([
            'name',
            'description',
            'frequency_type',
            'repeat_interval',
            'start_date',
            'end_date',
            'is_active',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('name', $payload)) {
            $payload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('cleaning_area_id', $validated)) {
            $this->resolveCleaningAreaInActiveCondominium((int) $validated['cleaning_area_id'], $activeCondominiumId);
            $payload['cleaning_area_id'] = (int) $validated['cleaning_area_id'];
        }

        if (array_key_exists('days_of_week', $validated)) {
            $payload['days_of_week'] = $this->normalizeDaysOfWeek($validated['days_of_week']);
        }

        $schedule->update($payload);

        return response()->json(
            $schedule->fresh()->load(['cleaningArea:id,condominium_id,name,is_active'])
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $schedule = CleaningSchedule::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $schedule->delete();

        return response()->json([
            'message' => 'Programacion de limpieza eliminada.',
        ]);
    }

    private function validateSchedulePayload(Request $request, int $activeCondominiumId, bool $partial = false): array
    {
        $requiredRule = $partial ? ['sometimes'] : ['required'];
        $validated = $request->validate([
            'cleaning_area_id' => [...$requiredRule, 'integer', 'exists:cleaning_areas,id'],
            'name' => [...$requiredRule, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'frequency_type' => [...$requiredRule, Rule::in($this->allowedFrequencyTypes())],
            'repeat_interval' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'days_of_week' => ['sometimes', 'nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:0', 'max:6'],
            'start_date' => [...$requiredRule, 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('cleaning_area_id', $validated)) {
            $this->resolveCleaningAreaInActiveCondominium((int) $validated['cleaning_area_id'], $activeCondominiumId);
        }

        $frequencyType = $validated['frequency_type'] ?? null;
        if ($frequencyType === CleaningSchedule::FREQUENCY_WEEKLY) {
            $days = $this->normalizeDaysOfWeek($validated['days_of_week'] ?? null);
            if (empty($days)) {
                throw ValidationException::withMessages([
                    'days_of_week' => ['Debes seleccionar al menos un dia para frecuencia semanal.'],
                ]);
            }
        }

        return $validated;
    }

    private function allowedFrequencyTypes(): array
    {
        return [
            CleaningSchedule::FREQUENCY_DAILY,
            CleaningSchedule::FREQUENCY_WEEKLY,
            CleaningSchedule::FREQUENCY_MONTHLY,
            CleaningSchedule::FREQUENCY_CUSTOM,
        ];
    }

    private function normalizeDaysOfWeek(?array $days): ?array
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
            ->where('id', $areaId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $area) {
            throw ValidationException::withMessages([
                'cleaning_area_id' => ['El area no pertenece al condominio activo.'],
            ]);
        }

        return $area;
    }
}



