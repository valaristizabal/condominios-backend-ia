<?php

namespace App\Modules\Cleaning\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cleaning\Models\CleaningChecklistItem;
use App\Modules\Cleaning\Models\CleaningRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CleaningChecklistItemController extends Controller
{
    public function indexByRecord(Request $request, int $recordId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($recordId, $activeCondominiumId);

        return response()->json(
            CleaningChecklistItem::query()
                ->where('cleaning_record_id', $record->id)
                ->orderBy('id')
                ->get()
        );
    }

    public function storeByRecord(Request $request, int $recordId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($recordId, $activeCondominiumId);

        if (strtolower((string) $record->status) === 'completed') {
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

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectForbiddenManagedFields($request);

        $item = CleaningChecklistItem::query()
            ->with('cleaningRecord')
            ->where('id', $id)
            ->firstOrFail();

        $record = $item->cleaningRecord;

        if (! $record || (int) $record->condominium_id !== $activeCondominiumId) {
            throw ValidationException::withMessages([
                'item_id' => ['El item de checklist no pertenece al condominio activo.'],
            ]);
        }

        if (strtolower((string) $record->status) === 'completed') {
            return response()->json([
                'message' => 'No se pueden actualizar items de un registro completado.',
            ], 400);
        }

        $validated = $request->validate([
            'completed' => ['required', 'boolean'],
            'item_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $payload = [];
        if (array_key_exists('completed', $validated)) {
            $payload['completed'] = (bool) $validated['completed'];
        }
        if (array_key_exists('item_name', $validated)) {
            $payload['item_name'] = trim((string) $validated['item_name']);
        }

        $item->update($payload);

        return response()->json($item->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $item = CleaningChecklistItem::query()
            ->with('cleaningRecord')
            ->where('id', $id)
            ->firstOrFail();

        $record = $item->cleaningRecord;

        if (! $record || (int) $record->condominium_id !== $activeCondominiumId) {
            throw ValidationException::withMessages([
                'item_id' => ['El item de checklist no pertenece al condominio activo.'],
            ]);
        }

        if (strtolower((string) $record->status) === 'completed') {
            return response()->json([
                'message' => 'No se pueden eliminar items de un registro completado.',
            ], 400);
        }

        $item->delete();

        return response()->json([
            'message' => 'Item de checklist eliminado.',
        ]);
    }

    public function updateByRecord(Request $request, int $recordId, int $itemId): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectForbiddenManagedFields($request);

        $record = $this->resolveCleaningRecordInActiveCondominium($recordId, $activeCondominiumId);

        if (strtolower((string) $record->status) === 'completed') {
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

