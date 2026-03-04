<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\CleaningArea;
use App\Models\CleaningChecklistItem;
use App\Models\CleaningRecord;
use App\Models\Operative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CleaningRecordController extends Controller
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_COMPLETED = 'completed';

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
        ]);

        $query = CleaningRecord::query()
            ->with([
                'cleaningArea:id,condominium_id,name,description,is_active',
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
                'checklistItems:id,cleaning_record_id,item_name,completed',
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

        return response()->json($query->get());
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

        $record = DB::transaction(function () use ($request, $validated, $activeCondominiumId, $area, $operative) {
            $newRecord = CleaningRecord::query()->create([
                'condominium_id' => $activeCondominiumId,
                'cleaning_area_id' => $area->id,
                'operative_id' => $operative->id,
                'registered_by_id' => $request->user()?->id,
                'cleaning_date' => $validated['cleaning_date'],
                'status' => self::STATUS_PENDING,
                'observations' => $validated['observations'] ?? null,
            ]);

            $templateItems = $area->checklistTemplateItems()
                ->orderBy('id')
                ->get(['item_name']);

            if ($templateItems->isNotEmpty()) {
                $now = now();
                $payload = $templateItems->map(fn ($item) => [
                    'cleaning_record_id' => $newRecord->id,
                    'item_name' => $item->item_name,
                    'completed' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                CleaningChecklistItem::query()->insert($payload);
            }

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
        ]);

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

    public function storeChecklistItem(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($id, $activeCondominiumId);

        if ($record->status === self::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'No se pueden agregar items a un registro completado.',
            ], 400);
        }

        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        $item = $record->checklistItems()->create([
            'item_name' => trim($validated['item_name']),
            'completed' => (bool) ($validated['completed'] ?? false),
        ]);

        return response()->json($item, 201);
    }

    public function updateChecklistItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectForbiddenManagedFields($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($id, $activeCondominiumId);

        if ($record->status === self::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'No se pueden actualizar items de un registro completado.',
            ], 400);
        }

        $validated = $request->validate([
            'completed' => ['required', 'boolean'],
        ]);

        $item = CleaningChecklistItem::query()
            ->where('cleaning_record_id', $record->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $item->update([
            'completed' => (bool) $validated['completed'],
        ]);

        return response()->json($item->fresh());
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
}
