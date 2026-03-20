<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VehicleIncidentController extends Controller
{
    private const INCIDENT_TYPE_ALIASES = [
        'robo' => 'unauthorized',
        'danio' => 'damage',
        'otro' => 'other',
    ];

    private const INCIDENT_TYPES = [
        'bad_parking',
        'unauthorized',
        'damage',
        'suspicious',
        'other',
    ];

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'pending' => ['nullable', 'boolean'],
            'resolved' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $query = VehicleIncident::query()
            ->with(['vehicle', 'apartment', 'registeredBy'])
            ->byCondominium($activeCondominiumId)
            ->orderByDesc('id');

        if (($validated['pending'] ?? false) === true) {
            $query->pending();
        } elseif (($validated['resolved'] ?? false) === true) {
            $query->resolved();
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 10)));
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'apartment_id' => ['nullable', 'integer', 'exists:apartments,id'],
            'plate' => ['nullable', 'string', 'max:20'],
            'incident_type' => ['required', 'string', 'max:100'],
            'observations' => ['required', 'string'],
            'evidence' => ['nullable', 'file', 'max:5120'],
        ]);

        if (! empty($validated['vehicle_id'])) {
            $vehicleBelongsToTenant = Vehicle::query()
                ->where('id', (int) $validated['vehicle_id'])
                ->where('condominium_id', $activeCondominiumId)
                ->exists();

            if (! $vehicleBelongsToTenant) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['El vehiculo no pertenece al condominio activo.'],
                ]);
            }
        }

        if (! empty($validated['apartment_id'])) {
            $apartmentBelongsToTenant = Apartment::query()
                ->where('id', (int) $validated['apartment_id'])
                ->where('condominium_id', $activeCondominiumId)
                ->exists();

            if (! $apartmentBelongsToTenant) {
                throw ValidationException::withMessages([
                    'apartment_id' => ['El apartamento no pertenece al condominio activo.'],
                ]);
            }
        }

        if (empty($validated['vehicle_id']) && empty($validated['plate'])) {
            throw ValidationException::withMessages([
                'plate' => ['La placa es obligatoria cuando no se envia vehicle_id.'],
            ]);
        }

        $normalizedIncidentType = $this->normalizeIncidentType((string) $validated['incident_type']);

        if (! in_array($normalizedIncidentType, self::INCIDENT_TYPES, true)) {
            throw ValidationException::withMessages([
                'incident_type' => ['El tipo de novedad no es valido.'],
            ]);
        }

        $evidencePath = $request->hasFile('evidence')
            ? $request->file('evidence')->store(
                sprintf('vehicle-incidents/condominium_%d/%s', (int) $activeCondominiumId, now()->format('Y/m/d')),
                'public'
            )
            : null;

        $incident = VehicleIncident::query()->create([
            'condominium_id' => (int) $activeCondominiumId,
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'apartment_id' => $validated['apartment_id'] ?? null,
            'registered_by_id' => $request->user()?->id,
            'plate' => ! empty($validated['plate']) ? strtoupper(trim($validated['plate'])) : null,
            'incident_type' => $normalizedIncidentType,
            'observations' => $validated['observations'],
            'evidence_path' => $evidencePath,
            'resolved' => false,
        ]);

        return response()->json(
            $incident->fresh()->load(['vehicle', 'apartment', 'registeredBy']),
            201
        );
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $incident = VehicleIncident::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->first();

        if (! $incident) {
            return response()->json([
                'message' => 'Incidente no encontrado para el condominio activo.',
            ], 404);
        }

        $incident->update([
            'resolved' => true,
        ]);

        return response()->json($incident->fresh()->load(['vehicle', 'apartment', 'registeredBy']));
    }

    private function rejectCondominiumIdFromRequest(Request $request): void
    {
        if ($request->query->has('condominium_id') || $request->request->has('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }

    private function normalizeIncidentType(string $incidentType): string
    {
        $normalized = mb_strtolower(trim($incidentType));
        return self::INCIDENT_TYPE_ALIASES[$normalized] ?? $normalized;
    }
}
