<?php

namespace App\Modules\Portfolio\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Portfolio\Models\PortfolioCharge;
use App\Modules\Portfolio\Models\PortfolioCollection;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortfolioChargeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'period' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'in:al_dia,proximo_a_vencer,en_mora,pagado'],
            'apartment_id' => ['nullable', 'integer'],
        ]);

        $periodFilter = $this->resolvePeriodFilter($validated['period'] ?? null);

        $charges = PortfolioCharge::query()
            ->with([
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.residents.user:id,full_name',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->when(
                ! empty($validated['apartment_id']),
                fn ($query) => $query->where('apartment_id', (int) $validated['apartment_id'])
            )
            ->when(
                $periodFilter['mode'] === 'month',
                fn ($query) => $query->whereDate('period', $periodFilter['period'])
            )
            ->when(
                ! empty($validated['status']),
                fn ($query) => $this->applyStatusFilter(
                    $query,
                    (string) $validated['status'],
                    now()->toDateString(),
                    now()->addDays(7)->toDateString()
                )
            )
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json($charges);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
            'period' => ['required', 'string', 'max:10'],
            'amount_total' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $apartment = $this->resolvePrimaryApartmentInActiveCondominium(
            (int) $validated['apartment_id'],
            $activeCondominiumId
        );

        $period = $this->normalizePeriod($validated['period']);
        $amountTotal = round((float) $validated['amount_total'], 2);
        $dueDate = CarbonImmutable::parse((string) $validated['due_date'])->toDateString();
        $balance = $amountTotal;
        $status = $this->resolveChargeStatus($balance, $dueDate);

        try {
            $charge = PortfolioCharge::query()->create([
                'condominium_id' => $activeCondominiumId,
                'apartment_id' => $apartment->id,
                'period' => $period,
                'amount_total' => $amountTotal,
                'amount_paid' => 0,
                'balance' => $balance,
                'due_date' => $dueDate,
                'status' => $status,
                'notes' => $validated['notes'] ?? null,
                'generated_by' => $request->user()?->id,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe una cartera mensual para esa unidad en el periodo indicado.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(
            $charge->fresh()->load([
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.residents.user:id,full_name',
            ]),
            201
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $charge = PortfolioCharge::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'apartment_id' => ['sometimes', 'integer', 'exists:apartments,id'],
            'period' => ['sometimes', 'string', 'max:10'],
            'amount_total' => ['sometimes', 'numeric', 'gt:0'],
            'due_date' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($this->isPastPeriod($charge->period?->toDateString() ?? null)) {
            $forbiddenFields = ['apartment_id', 'period', 'amount_total', 'due_date'];
            foreach ($forbiddenFields as $field) {
                if (array_key_exists($field, $validated)) {
                    throw ValidationException::withMessages([
                        $field => ['No se permite sobrescribir cartera de meses anteriores.'],
                    ]);
                }
            }
        }

        if (array_key_exists('apartment_id', $validated)) {
            $this->resolvePrimaryApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);
        }

        $payload = [];

        if (array_key_exists('apartment_id', $validated)) {
            $payload['apartment_id'] = (int) $validated['apartment_id'];
        }

        if (array_key_exists('period', $validated)) {
            $payload['period'] = $this->normalizePeriod((string) $validated['period']);
        }

        $amountTotal = (float) $charge->amount_total;
        if (array_key_exists('amount_total', $validated)) {
            $amountTotal = round((float) $validated['amount_total'], 2);
            $payload['amount_total'] = $amountTotal;
        }

        if (array_key_exists('due_date', $validated)) {
            $payload['due_date'] = CarbonImmutable::parse((string) $validated['due_date'])->toDateString();
        }

        if (array_key_exists('notes', $validated)) {
            $payload['notes'] = $validated['notes'];
        }

        $amountPaid = round((float) PortfolioCollection::query()
            ->where('charge_id', $charge->id)
            ->sum('amount'), 2);

        if ($amountTotal < $amountPaid) {
            throw ValidationException::withMessages([
                'amount_total' => ['El valor total no puede ser menor al total recaudado ya aplicado.'],
            ]);
        }

        $resolvedDueDate = $payload['due_date'] ?? ($charge->due_date?->toDateString() ?? now()->toDateString());
        $balance = round($amountTotal - $amountPaid, 2);

        $payload['amount_paid'] = $amountPaid;
        $payload['balance'] = $balance;
        $payload['status'] = $this->resolveChargeStatus($balance, $resolvedDueDate);

        try {
            $charge->update($payload);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json([
                    'message' => 'Ya existe una cartera mensual para esa unidad en el periodo indicado.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(
            $charge->fresh()->load([
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.residents.user:id,full_name',
            ])
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:10'],
        ]);

        $periodFilter = $this->resolvePeriodFilter($validated['period'] ?? null);
        $today = now()->toDateString();
        $upcomingLimit = now()->addDays(7)->toDateString();

        $chargesQuery = PortfolioCharge::query()
            ->where('condominium_id', $activeCondominiumId);

        if ($periodFilter['mode'] === 'month') {
            $chargesQuery->whereDate('period', $periodFilter['period']);
        }

        $collectionsQuery = PortfolioCollection::query()
            ->where('condominium_id', $activeCondominiumId);

        if ($periodFilter['mode'] === 'month') {
            $periodValue = (string) $periodFilter['period'];
            $collectionsQuery->whereHas('charge', fn ($query) => $query->whereDate('period', $periodValue));
        }

        $totalCollected = round((float) $collectionsQuery->sum('amount'), 2);
        $pendingPortfolio = round((float) (clone $chargesQuery)->sum('balance'), 2);
        $overdueUnits = (int) (clone $chargesQuery)
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<', $today)
            ->distinct('apartment_id')
            ->count('apartment_id');
        $upcomingDue = (int) (clone $chargesQuery)
            ->where('balance', '>', 0)
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $upcomingLimit)
            ->distinct('apartment_id')
            ->count('apartment_id');

        return response()->json([
            'period' => $periodFilter['mode'] === 'all' ? 'all' : substr((string) $periodFilter['period'], 0, 7),
            'total_recaudado' => $totalCollected,
            'cartera_pendiente' => $pendingPortfolio,
            'unidades_en_mora' => $overdueUnits,
            'vencimientos_proximos' => $upcomingDue,
            'total_collected' => $totalCollected,
            'pending_portfolio' => $pendingPortfolio,
            'overdue_units' => $overdueUnits,
            'upcoming_due' => $upcomingDue,
        ]);
    }

    public function portfolioStatus(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'period' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'in:al_dia,proximo_a_vencer,en_mora,pagado'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $periodFilter = $this->resolvePeriodFilter($validated['period'] ?? null);
        $today = now()->startOfDay();
        $todayDate = $today->toDateString();
        $upcomingLimit = $today->copy()->addDays(7)->toDateString();

        $kpiChargesQuery = PortfolioCharge::query()
            ->where('condominium_id', $activeCondominiumId);

        if ($periodFilter['mode'] === 'month') {
            $kpiChargesQuery->whereDate('period', $periodFilter['period']);
        }

        $totalCharged = round((float) (clone $kpiChargesQuery)->sum('amount_total'), 2);
        $totalPending = round((float) (clone $kpiChargesQuery)->sum('balance'), 2);

        $kpiCollectionsQuery = PortfolioCollection::query()
            ->where('condominium_id', $activeCondominiumId);

        if ($periodFilter['mode'] === 'month') {
            $periodValue = (string) $periodFilter['period'];
            $kpiCollectionsQuery->whereHas('charge', fn ($query) => $query->whereDate('period', $periodValue));
        }

        $totalCollected = round((float) $kpiCollectionsQuery->sum('amount'), 2);
        $collectionRate = $totalCharged > 0
            ? round(($totalCollected / $totalCharged) * 100, 2)
            : 0.0;

        $query = PortfolioCharge::query()
            ->with([
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.residents.user:id,full_name',
            ])
            ->where('condominium_id', $activeCondominiumId);

        if ($periodFilter['mode'] === 'month') {
            $query->whereDate('period', $periodFilter['period']);
        }

        if (! empty($validated['q'])) {
            $search = trim((string) $validated['q']);
            $query->whereHas('apartment', function ($apartmentQuery) use ($search) {
                $apartmentQuery->where('number', 'like', '%' . $search . '%')
                    ->orWhere('tower', 'like', '%' . $search . '%')
                    ->orWhereHas('residents.user', function ($userQuery) use ($search) {
                        $userQuery->where('full_name', 'like', '%' . $search . '%');
                    });
            });
        }

        if (! empty($validated['status'])) {
            $query = $this->applyStatusFilter($query, (string) $validated['status'], $todayDate, $upcomingLimit);
        }

        $paginator = $query
            ->orderByDesc('period')
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        $paginator->getCollection()->transform(function (PortfolioCharge $charge) use ($today) {
            $dueDate = $charge->due_date?->startOfDay();
            $daysOverdue = 0;

            if ($dueDate && (float) $charge->balance > 0 && $dueDate->lt($today)) {
                $daysOverdue = $dueDate->diffInDays($today);
            }

            $status = $this->resolveChargeStatus(
                (float) $charge->balance,
                $charge->due_date?->toDateString() ?? now()->toDateString()
            );

            return [
                'id' => $charge->id,
                'charge_id' => $charge->id,
                'apartment_id' => $charge->apartment_id,
                'unit' => $this->formatApartmentLabel($charge->apartment),
                'unidad' => $this->formatApartmentLabel($charge->apartment),
                'owner' => $this->resolveApartmentOwnerName($charge->apartment),
                'propietario' => $this->resolveApartmentOwnerName($charge->apartment),
                'period' => $charge->period?->format('Y-m'),
                'amount_total' => (float) $charge->amount_total,
                'amount_paid' => (float) $charge->amount_paid,
                'balance' => (float) $charge->balance,
                'due_date' => $charge->due_date?->toDateString(),
                'fecha_vencimiento' => $charge->due_date?->toDateString(),
                'days_overdue' => $daysOverdue,
                'dias_en_mora' => $daysOverdue,
                'status' => $status,
                'estado' => $status,
            ];
        });

        return response()->json([
            'kpis' => [
                'total_charged' => $totalCharged,
                'total_collected' => $totalCollected,
                'total_pending' => $totalPending,
                'porcentaje_recaudo' => $collectionRate,
            ],
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function unitOptions(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:10'],
        ]);

        $periodFilter = $this->resolvePeriodFilter($validated['period'] ?? null);
        $period = $periodFilter['mode'] === 'all'
            ? now()->startOfMonth()->toDateString()
            : (string) $periodFilter['period'];

        $ownerNamesByApartment = Resident::query()
            ->join('users', 'users.id', '=', 'residents.user_id')
            ->join('apartments', 'apartments.id', '=', 'residents.apartment_id')
            ->where('apartments.condominium_id', $activeCondominiumId)
            ->where('residents.type', 'propietario')
            ->where('residents.is_active', true)
            ->groupBy('residents.apartment_id')
            ->selectRaw('residents.apartment_id, MIN(users.full_name) as owner_name')
            ->pluck('owner_name', 'residents.apartment_id');

        $apartments = Apartment::query()
            ->select(['id', 'tower', 'number', 'unit_type_id'])
            ->where('condominium_id', $activeCondominiumId)
            ->whereHas('unitType', fn ($query) => $query->where('allows_residents', true))
            ->orderBy('tower')
            ->orderBy('number')
            ->get();

        $chargesByApartment = PortfolioCharge::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('period', $period)
            ->get(['id', 'apartment_id'])
            ->keyBy('apartment_id');

        $rows = $apartments->map(function (Apartment $apartment) use ($chargesByApartment, $ownerNamesByApartment) {
            $charge = $chargesByApartment->get($apartment->id);

            return [
                'apartment_id' => (int) $apartment->id,
                'unit_label' => $this->formatApartmentOptionLabel($apartment),
                'owner_name' => (string) ($ownerNamesByApartment->get($apartment->id) ?? '-'),
                'charge_id' => $charge?->id ? (int) $charge->id : null,
            ];
        })->values();

        return response()->json($rows);
    }

    public function generateCurrent(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $periodStart = CarbonImmutable::now()->startOfMonth();
        $normalizedPeriod = $periodStart->toDateString();

        $alreadyGeneratedCount = PortfolioCharge::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('period', $normalizedPeriod)
            ->count();

        if ($alreadyGeneratedCount > 0) {
            return response()->json([
                'period' => $periodStart->format('Y-m'),
                'total_creados' => 0,
                'total_omitidos' => $alreadyGeneratedCount,
                'message' => 'La cartera del mes actual ya fue generada',
            ]);
        }

        $result = $this->generatePortfolioForPeriod(
            $activeCondominiumId,
            $normalizedPeriod,
            (int) ($request->user()?->id ?? 0)
        );

        $result['message'] = 'Cartera del mes actual generada correctamente';

        return response()->json($result);
    }

    private function generatePortfolioForPeriod(
        int $activeCondominiumId,
        string $normalizedPeriod,
        int $generatedByUserId = 0
    ): array {
        $periodStart = CarbonImmutable::createFromFormat('Y-m-d', $normalizedPeriod)->startOfMonth();
        $generatedBy = $generatedByUserId > 0 ? $generatedByUserId : null;

        $existingApartmentIds = PortfolioCharge::query()
            ->where('condominium_id', $activeCondominiumId)
            ->whereDate('period', $normalizedPeriod)
            ->pluck('apartment_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $existingApartmentIdSet = array_fill_keys($existingApartmentIds, true);

        $residents = Resident::query()
            ->where('is_active', true)
            ->whereNotNull('administration_fee')
            ->whereNotNull('administration_due_day')
            ->whereBetween('administration_due_day', [1, 31])
            ->whereHas('apartment', fn ($query) => $query->where('condominium_id', $activeCondominiumId))
            ->orderBy('id')
            ->get([
                'id',
                'apartment_id',
                'administration_fee',
                'administration_due_day',
            ])
            ->keyBy('apartment_id')
            ->values();

        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($residents as $resident) {
            $apartmentId = (int) $resident->apartment_id;

            if (isset($existingApartmentIdSet[$apartmentId])) {
                $totalSkipped++;
                continue;
            }

            $administrationFee = round((float) $resident->administration_fee, 2);
            $dueDay = (int) $resident->administration_due_day;
            $safeDay = min($dueDay, $periodStart->daysInMonth);
            $dueDate = $periodStart->day($safeDay)->toDateString();
            $balance = $administrationFee;

            try {
                PortfolioCharge::query()->create([
                    'condominium_id' => $activeCondominiumId,
                    'apartment_id' => $apartmentId,
                    'period' => $periodStart->toDateString(),
                    'amount_total' => $administrationFee,
                    'amount_paid' => 0,
                    'balance' => $balance,
                    'due_date' => $dueDate,
                    'status' => $this->resolveChargeStatus($balance, $dueDate),
                    'generated_by' => $generatedBy,
                ]);

                $totalCreated++;
                $existingApartmentIdSet[$apartmentId] = true;
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $totalSkipped++;
                    $existingApartmentIdSet[$apartmentId] = true;
                    continue;
                }

                throw $exception;
            }
        }

        return [
            'period' => $periodStart->format('Y-m'),
            'total_creados' => $totalCreated,
            'total_omitidos' => $totalSkipped,
        ];
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

    private function resolvePrimaryApartmentInActiveCondominium(int $apartmentId, int $activeCondominiumId): Apartment
    {
        $apartment = Apartment::query()
            ->with('unitType:id,name,allows_residents,requires_parent')
            ->where('id', $apartmentId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $apartment) {
            throw ValidationException::withMessages([
                'apartment_id' => ['El apartamento no pertenece al condominio activo.'],
            ]);
        }

        if (! $apartment->isPrimaryApartment()) {
            throw ValidationException::withMessages([
                'apartment_id' => ['Solo se permite cartera para inmuebles principales.'],
            ]);
        }

        return $apartment;
    }

    private function normalizePeriod(string $value): string
    {
        $normalized = trim($value);

        if (preg_match('/^\d{4}-\d{2}$/', $normalized) === 1) {
            return CarbonImmutable::createFromFormat('Y-m', $normalized)
                ->startOfMonth()
                ->toDateString();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
            return CarbonImmutable::parse($normalized)->startOfMonth()->toDateString();
        }

        throw ValidationException::withMessages([
            'period' => ['El periodo debe tener formato YYYY-MM o YYYY-MM-DD.'],
        ]);
    }

    /**
     * @return array{mode: 'month'|'all', period?: string}
     */
    private function resolvePeriodFilter(?string $period): array
    {
        $normalized = trim((string) $period);

        if ($normalized === '' || $normalized === 'current') {
            return [
                'mode' => 'month',
                'period' => now()->startOfMonth()->toDateString(),
            ];
        }

        if (in_array(mb_strtolower($normalized), ['all', 'historico', 'historial'], true)) {
            return ['mode' => 'all'];
        }

        return [
            'mode' => 'month',
            'period' => $this->normalizePeriod($normalized),
        ];
    }

    private function resolveChargeStatus(float $balance, string $dueDate): string
    {
        $normalizedBalance = round($balance, 2);
        if ($normalizedBalance <= 0) {
            return 'pagado';
        }

        $today = now()->startOfDay();
        $due = CarbonImmutable::parse($dueDate)->startOfDay();

        if ($due->lt($today)) {
            return 'en_mora';
        }

        if ($due->lte($today->copy()->addDays(7))) {
            return 'proximo_a_vencer';
        }

        return 'al_dia';
    }

    private function isPastPeriod(?string $period): bool
    {
        if (! $period) {
            return false;
        }

        $periodStart = CarbonImmutable::parse($period)->startOfMonth();
        $currentStart = CarbonImmutable::now()->startOfMonth();

        return $periodStart->lt($currentStart);
    }

    private function applyStatusFilter($query, string $status, string $today, string $upcomingLimit)
    {
        return match ($status) {
            'pagado' => $query->where('balance', '<=', 0),
            'en_mora' => $query->where('balance', '>', 0)->whereDate('due_date', '<', $today),
            'proximo_a_vencer' => $query
                ->where('balance', '>', 0)
                ->whereDate('due_date', '>=', $today)
                ->whereDate('due_date', '<=', $upcomingLimit),
            'al_dia' => $query->where(function ($subQuery) use ($upcomingLimit) {
                $subQuery->where('balance', '<=', 0)
                    ->orWhere(function ($openQuery) use ($upcomingLimit) {
                        $openQuery->where('balance', '>', 0)
                            ->whereDate('due_date', '>', $upcomingLimit);
                    });
            }),
            default => $query,
        };
    }

    private function formatApartmentLabel(?Apartment $apartment): string
    {
        if (! $apartment) {
            return 'Unidad sin definir';
        }

        $tower = trim((string) ($apartment->tower ?? ''));
        $number = trim((string) ($apartment->number ?? ''));

        if ($tower !== '' && $number !== '') {
            return sprintf('Torre %s-%s', $tower, $number);
        }

        if ($number !== '') {
            return 'Apto ' . $number;
        }

        return 'Unidad sin definir';
    }

    private function formatApartmentOptionLabel(?Apartment $apartment): string
    {
        if (! $apartment) {
            return 'Unidad sin definir';
        }

        $tower = trim((string) ($apartment->tower ?? ''));
        $number = trim((string) ($apartment->number ?? ''));

        if ($tower !== '' && $number !== '') {
            return sprintf('Torre %s - Apto %s', $tower, $number);
        }

        if ($number !== '') {
            return 'Apto ' . $number;
        }

        return 'Unidad sin definir';
    }

    private function resolveApartmentOwnerName(?Apartment $apartment): string
    {
        if (! $apartment || ! $apartment->relationLoaded('residents')) {
            return '-';
        }

        $residents = $apartment->residents ?? collect();
        $owner = $residents->first(fn ($resident) => $resident->type === 'propietario' && $resident->user);
        if ($owner?->user?->full_name) {
            return (string) $owner->user->full_name;
        }

        $fallback = $residents->first(fn ($resident) => (bool) $resident->user);
        return $fallback?->user?->full_name ? (string) $fallback->user->full_name : '-';
    }
}
