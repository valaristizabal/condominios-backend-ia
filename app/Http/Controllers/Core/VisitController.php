<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitController extends Controller
{
    private const STATUS_INSIDE = 'INSIDE';
    private const STATUS_OUTSIDE = 'OUTSIDE';

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

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 10)));
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $request->attributes->get('activeCondominiumId');
        $this->rejectCondominiumIdFromRequest($request);
        $this->rejectStatusFromRequest($request);

        $validated = $request->validate([
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'document_number' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'destination' => ['nullable', 'string', 'max:255'],
            'background_check' => ['sometimes', 'boolean'],
            'carried_items' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $apartment = $this->resolveApartmentInActiveCondominium(
            (int) $validated['apartment_id'],
            (int) $activeCondominiumId
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
            $visit->fresh()->load([
                'apartment:id,unit_type_id,tower,number,floor',
                'registeredBy:id,full_name,email,document_number',
            ]),
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

        $visit->update([
            'check_out_at' => now(),
            'status' => self::STATUS_OUTSIDE,
        ]);

        return response()->json(
            $visit->fresh()->load([
                'apartment:id,unit_type_id,tower,number,floor',
                'registeredBy:id,full_name,email,document_number',
            ])
        );
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
}
