<?php

namespace App\Modules\Vehicles\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Vehicles\Models\Vehicle;
use App\Modules\Vehicles\Models\VehicleIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
            ->with($this->incidentRelations())
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
            'evidence' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'evidences' => ['nullable', 'array', 'max:10'],
            'evidences.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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

        $storageDirectory = sprintf('vehicle-incidents/condominium_%d/%s', (int) $activeCondominiumId, now()->format('Y/m/d'));

        $evidencePaths = [];

        foreach ($request->file('evidences', []) as $evidenceFile) {
            $evidencePaths[] = $evidenceFile->store($storageDirectory, 'public');
        }

        if (empty($evidencePaths) && $request->hasFile('evidence')) {
            $evidencePaths[] = $request->file('evidence')->store($storageDirectory, 'public');
        }

        $incidentPayload = [
            'condominium_id' => (int) $activeCondominiumId,
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'apartment_id' => $validated['apartment_id'] ?? null,
            'registered_by_id' => $request->user()?->id,
            'plate' => ! empty($validated['plate']) ? strtoupper(trim($validated['plate'])) : null,
            'incident_type' => $normalizedIncidentType,
            'observations' => $validated['observations'],
            'evidence_path' => $evidencePaths[0] ?? null,
            'resolved' => false,
        ];

        if ($this->supportsEvidencePaths()) {
            $incidentPayload['evidence_paths'] = $evidencePaths;
        }

        $incident = VehicleIncident::query()->create($incidentPayload);

        return response()->json(
            $incident->fresh()->load($this->incidentRelations()),
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

        return response()->json($incident->fresh()->load($this->incidentRelations()));
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

    private function supportsEvidencePaths(): bool
    {
        return Schema::hasColumn('vehicle_incidents', 'evidence_paths');
    }

    private function incidentRelations(): array
    {
        return ['vehicle', 'apartment.unitType', 'registeredBy'];
    }
}




