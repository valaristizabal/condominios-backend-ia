<?php

namespace App\Modules\Emergencies\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cleaning\Models\CleaningArea;
use App\Modules\Emergencies\Models\HealthIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HealthIncidentController extends Controller
{
    public function areas(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $areas = CleaningArea::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($areas);
    }

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $incidents = HealthIncident::query()
            ->with([
                'emergencyType:id,name,level',
                'reportedBy:id,full_name',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get();

        return response()->json($incidents);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'emergency_type_id' => [
                'required',
                'integer',
                Rule::exists('emergency_types', 'id')
                    ->where(fn ($query) => $query->where('condominium_id', $activeCondominiumId)),
            ],
            'event_type' => ['required', 'string', 'max:100'],
            'event_location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_date' => ['required', 'date'],
            'reported_by_id' => ['prohibited'],
            'status' => ['prohibited'],
            'resolved_at' => ['prohibited'],
        ]);

        $incident = HealthIncident::query()->create([
            'condominium_id' => $activeCondominiumId,
            'emergency_type_id' => (int) $validated['emergency_type_id'],
            'reported_by_id' => $request->user()->id,
            'event_type' => $validated['event_type'],
            'event_location' => $validated['event_location'] ?? null,
            'description' => $validated['description'] ?? null,
            'event_date' => $validated['event_date'],
            'status' => HealthIncident::STATUS_OPEN,
            'resolved_at' => null,
        ]);

        return response()->json(
            $incident->load([
                'emergencyType:id,name,level',
                'reportedBy:id,full_name',
            ]),
            201
        );
    }

    public function progress(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $incident = HealthIncident::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($incident->status === HealthIncident::STATUS_CLOSED) {
            return response()->json([
                'message' => 'No se puede pasar a progreso una emergencia cerrada.',
            ], 400);
        }

        if ($incident->status === HealthIncident::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'La emergencia ya esta en progreso.',
            ], 400);
        }

        $incident->status = HealthIncident::STATUS_IN_PROGRESS;
        $incident->save();

        return response()->json([
            'message' => 'Emergencia actualizada a en progreso.',
            'data' => $incident->fresh(['emergencyType:id,name,level', 'reportedBy:id,full_name']),
        ]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $incident = HealthIncident::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($incident->status === HealthIncident::STATUS_CLOSED) {
            return response()->json([
                'message' => 'La emergencia ya se encuentra cerrada.',
            ], 400);
        }

        $incident->status = HealthIncident::STATUS_CLOSED;
        $incident->resolved_at = now();
        $incident->save();

        return response()->json([
            'message' => 'Emergencia cerrada correctamente.',
            'data' => $incident->fresh(['emergencyType:id,name,level', 'reportedBy:id,full_name']),
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
}




