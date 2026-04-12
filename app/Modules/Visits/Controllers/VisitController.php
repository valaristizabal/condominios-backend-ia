<?php

namespace App\Modules\Visits\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Core\Models\UnitType;
use App\Modules\Visits\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitController extends Controller
{
    private const STATUS_INSIDE = 'INSIDE';
    private const STATUS_OUTSIDE = 'OUTSIDE';

    public function bootstrapData(Request $request): JsonResponse
    {
        $activeCondominiumId = (int) $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $unitTypes = UnitType::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        $apartments = Apartment::query()
            ->with(['unitType:id,name'])
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('tower')
            ->orderBy('number')
            ->get(['id', 'unit_type_id', 'tower', 'number', 'floor', 'is_active']);

        return response()->json([
            'unit_types' => $unitTypes,
            'apartments' => $apartments,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([self::STATUS_INSIDE, self::STATUS_OUTSIDE])],
            'only_active' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $query = Visit::query()
            ->with([
                'apartment:id,unit_type_id,tower,number,floor',
                'registeredBy:id,full_name,email,document_number',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('check_in_at')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (($validated['only_active'] ?? false) === true) {
            $query->whereNull('check_out_at');
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('check_in_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('check_in_at', '<=', $validated['date_to']);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 10));
        $paginator->getCollection()->transform(fn (Visit $visit) => $this->transformVisit($visit));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectStatusFromRequest($request);

        $validated = $request->validate([
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'document_number' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'destination' => ['nullable', 'string', 'max:255'],
            'background_check' => ['sometimes', 'boolean'],
            'carried_items' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $apartment = $this->resolveApartmentInActiveCondominium(
            (int) $validated['apartment_id'],
            (int) $activeCondominiumId
        );
        $this->rejectDuplicateActiveVisit(
            $activeCondominiumId,
            $validated['document_number']
        );

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store(
                sprintf('visits/condominium_%d/%s', (int) $activeCondominiumId, now()->format('Y/m/d')),
                'public'
            )
            : null;

        $visit = Visit::query()->create([
            'condominium_id' => $activeCondominiumId,
            'apartment_id' => $apartment->id,
            'registered_by_id' => $request->user()?->id,
            'full_name' => $validated['full_name'],
            'document_number' => $validated['document_number'],
            'phone' => $validated['phone'] ?? null,
            'destination' => $validated['destination'] ?? null,
            'background_check' => (bool) ($validated['background_check'] ?? false),
            'carried_items' => $validated['carried_items'] ?? null,
            'photo' => $photoPath,
            'status' => self::STATUS_INSIDE,
            'check_in_at' => now(),
            'check_out_at' => null,
        ]);

        return response()->json(
            $this->transformVisit(
                $visit->fresh()->load([
                    'apartment:id,unit_type_id,tower,number,floor',
                    'registeredBy:id,full_name,email,document_number',
                ])
            ),
            201
        );
    }

    public function checkout(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectStatusFromRequest($request);

        $visit = Visit::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($visit->status !== self::STATUS_INSIDE) {
            throw ValidationException::withMessages([
                'status' => ['La visita no se encuentra en estado INSIDE.'],
            ]);
        }

        $checkedOutAt = now();

        $visit->update([
            'check_out_at' => $checkedOutAt,
            'status' => self::STATUS_OUTSIDE,
        ]);

        return response()->json(
            $this->transformVisit(
                $visit->fresh()->load([
                    'apartment:id,unit_type_id,tower,number,floor',
                    'registeredBy:id,full_name,email,document_number',
                ])
            )
        );
    }

    private function transformVisit(Visit $visit): array
    {
        $checkInAt = $visit->check_in_at;
        $checkOutAt = $visit->check_out_at;
        $stayMinutes = null;

        if ($checkInAt && $checkOutAt) {
            $stayMinutes = max(0, $checkInAt->diffInMinutes($checkOutAt));
        }

        return [
            'id' => $visit->id,
            'condominium_id' => $visit->condominium_id,
            'apartment_id' => $visit->apartment_id,
            'registered_by_id' => $visit->registered_by_id,
            'full_name' => $visit->full_name,
            'document_number' => $visit->document_number,
            'phone' => $visit->phone,
            'destination' => $visit->destination,
            'background_check' => (bool) $visit->background_check,
            'carried_items' => $visit->carried_items,
            'photo' => $visit->photo,
            'status' => $visit->status,
            'check_in_at' => optional($checkInAt)->toIso8601String(),
            'check_out_at' => optional($checkOutAt)->toIso8601String(),
            'stay_minutes' => $stayMinutes,
            'created_at' => optional($visit->created_at)->toIso8601String(),
            'updated_at' => optional($visit->updated_at)->toIso8601String(),
            'apartment' => $visit->apartment,
            'registered_by' => $visit->registeredBy,
        ];
    }

    private function rejectCondominiumIdFromRequest(Request $request): void
    {
        if ($request->query->has('condominium_id') || $request->request->has('condominium_id')) {
            throw ValidationException::withMessages([
                'condominium_id' => ['No se permite enviar condominium_id en este endpoint.'],
            ]);
        }
    }

    private function rejectStatusFromRequest(Request $request): void
    {
        if ($request->query->has('status') || $request->request->has('status')) {
            throw ValidationException::withMessages([
                'status' => ['No se permite enviar status en este endpoint.'],
            ]);
        }
    }

    private function resolveApartmentInActiveCondominium(int $apartmentId, int $activeCondominiumId): Apartment
    {
        $apartment = Apartment::query()
            ->where('id', $apartmentId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $apartment) {
            throw ValidationException::withMessages([
                'apartment_id' => ['El apartamento no pertenece al condominio activo.'],
            ]);
        }

        return $apartment;
    }

    private function rejectDuplicateActiveVisit(int $activeCondominiumId, string $documentNumber): void
    {
        $duplicateExists = Visit::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('document_number', $documentNumber)
            ->where('status', self::STATUS_INSIDE)
            ->whereNull('check_out_at')
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'document_number' => ['Este visitante ya tiene un ingreso activo. Debes registrar la salida antes de volver a ingresarlo.'],
            ]);
        }
    }
}



