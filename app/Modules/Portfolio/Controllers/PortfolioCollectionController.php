<?php

namespace App\Modules\Portfolio\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Apartment;
use App\Modules\Portfolio\Models\PortfolioCharge;
use App\Modules\Portfolio\Models\PortfolioCollection;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PortfolioCollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
            'period' => ['nullable', 'string', 'max:10'],
            'apartment_id' => ['nullable', 'integer', 'exists:apartments,id'],
            'charge_id' => ['nullable', 'integer', 'exists:portfolio_charges,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        if (! empty($validated['apartment_id'])) {
            $this->ensureApartmentInActiveCondominium((int) $validated['apartment_id'], $activeCondominiumId);
        }

        if (! empty($validated['charge_id'])) {
            $this->ensureChargeInActiveCondominium((int) $validated['charge_id'], $activeCondominiumId);
        }

        $periodFilter = $this->resolvePeriodFilter($validated['period'] ?? null);

        $query = PortfolioCollection::query()
            ->with([
                'charge:id,apartment_id,period,due_date,status,balance',
                'apartment:id,unit_type_id,tower,number',
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.residents.user:id,full_name',
                'createdBy:id,full_name,email',
            ])
            ->where('condominium_id', $activeCondominiumId);

        if ($periodFilter['mode'] === 'month') {
            $periodValue = (string) $periodFilter['period'];
            $query->whereHas('charge', fn ($chargeQuery) => $chargeQuery->whereDate('period', $periodValue));
        }

        if (! empty($validated['apartment_id'])) {
            $query->where('apartment_id', (int) $validated['apartment_id']);
        }

        if (! empty($validated['charge_id'])) {
            $query->where('charge_id', (int) $validated['charge_id']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('payment_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('payment_date', '<=', $validated['date_to']);
        }

        $paginator = $query
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10), ['*'], 'page', (int) ($validated['page'] ?? 1));

        $paginator->getCollection()->transform(fn (PortfolioCollection $collection) => $this->presentCollection($collection));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'charge_id' => ['required', 'integer', 'exists:portfolio_charges,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $charge = $this->ensureChargeInActiveCondominium((int) $validated['charge_id'], $activeCondominiumId);
        $amount = round((float) $validated['amount'], 2);
        $paymentDate = CarbonImmutable::parse((string) $validated['payment_date'])->toDateString();

        $storageDirectory = sprintf(
            'portfolio-collections/condominium_%d/%s',
            $activeCondominiumId,
            now()->format('Y/m/d')
        );

        $collection = DB::transaction(function () use (
            $validated,
            $request,
            $charge,
            $activeCondominiumId,
            $amount,
            $paymentDate,
            $storageDirectory
        ) {
            $lockedCharge = PortfolioCharge::query()
                ->where('id', $charge->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $lockedCharge->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['El valor recaudado supera el saldo pendiente de la deuda mensual.'],
                ]);
            }

            $evidencePath = null;
            $evidenceName = null;
            if ($request->hasFile('evidence')) {
                $file = $request->file('evidence');
                $evidencePath = $file?->store($storageDirectory, 'public');
                $evidenceName = $file?->getClientOriginalName();
            }

            $newCollection = PortfolioCollection::query()->create([
                'condominium_id' => $activeCondominiumId,
                'charge_id' => $lockedCharge->id,
                'apartment_id' => $lockedCharge->apartment_id,
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'evidence_path' => $evidencePath,
                'evidence_name' => $evidenceName,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->refreshChargeFinancialState($lockedCharge);

            return $newCollection;
        });

        return response()->json(
            $this->presentCollection($collection->fresh()->load([
                'charge:id,apartment_id,period,due_date,status,balance',
                'apartment:id,unit_type_id,tower,number',
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.residents.user:id,full_name',
                'createdBy:id,full_name,email',
            ])),
            201
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $collection = PortfolioCollection::query()
            ->with([
                'charge:id,apartment_id,period,due_date,status,balance',
                'apartment:id,unit_type_id,tower,number',
                'apartment.unitType:id,name,allows_residents,requires_parent',
                'apartment.residents.user:id,full_name',
                'createdBy:id,full_name,email',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json($this->presentCollection($collection));
    }

    private function refreshChargeFinancialState(PortfolioCharge $charge): void
    {
        $amountPaid = round((float) PortfolioCollection::query()
            ->where('charge_id', $charge->id)
            ->sum('amount'), 2);

        $amountTotal = round((float) $charge->amount_total, 2);
        $balance = round($amountTotal - $amountPaid, 2);
        $status = $this->resolveChargeStatus($balance, $charge->due_date?->toDateString() ?? now()->toDateString());

        $charge->update([
            'amount_paid' => $amountPaid,
            'balance' => $balance,
            'status' => $status,
        ]);
    }

    private function resolveChargeStatus(float $balance, string $dueDate): string
    {
        $normalizedBalance = round($balance, 2);
        if ($normalizedBalance <= 0) {
            return 'pagada';
        }

        $today = now()->startOfDay();
        $due = CarbonImmutable::parse($dueDate)->startOfDay();

        if ($due->lt($today)) {
            return 'en_mora';
        }

        if ($due->lte($today->copy()->addDays(7))) {
            return 'proximo_vencer';
        }

        return 'al_dia';
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

        if (mb_strtolower($normalized) === 'all') {
            return ['mode' => 'all'];
        }

        if (preg_match('/^\d{4}-\d{2}$/', $normalized) === 1) {
            return [
                'mode' => 'month',
                'period' => CarbonImmutable::createFromFormat('Y-m', $normalized)->startOfMonth()->toDateString(),
            ];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
            return [
                'mode' => 'month',
                'period' => CarbonImmutable::parse($normalized)->startOfMonth()->toDateString(),
            ];
        }

        throw ValidationException::withMessages([
            'period' => ['El periodo debe tener formato YYYY-MM o YYYY-MM-DD.'],
        ]);
    }

    private function presentCollection(PortfolioCollection $collection): array
    {
        return [
            'id' => $collection->id,
            'charge_id' => $collection->charge_id,
            'apartment_id' => $collection->apartment_id,
            'unit' => $this->formatApartmentLabel($collection->apartment),
            'owner' => $this->resolveApartmentOwnerName($collection->apartment),
            'amount' => (float) $collection->amount,
            'payment_date' => $collection->payment_date?->toDateString(),
            'period' => $collection->charge?->period?->format('Y-m'),
            'evidence_name' => $collection->evidence_name,
            'evidence_path' => $collection->evidence_path,
            'evidence_url' => $this->resolveEvidenceUrl($collection->evidence_path),
            'notes' => $collection->notes,
            'status' => $collection->charge?->status,
            'created_by' => $collection->created_by,
            'created_by_name' => $collection->createdBy?->full_name,
            'created_at' => $collection->created_at?->toDateTimeString(),
        ];
    }

    private function resolveEvidenceUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
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

    private function ensureApartmentInActiveCondominium(int $apartmentId, int $activeCondominiumId): Apartment
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

    private function ensureChargeInActiveCondominium(int $chargeId, int $activeCondominiumId): PortfolioCharge
    {
        $charge = PortfolioCharge::query()
            ->where('id', $chargeId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $charge) {
            throw ValidationException::withMessages([
                'charge_id' => ['La deuda mensual no pertenece al condominio activo.'],
            ]);
        }

        return $charge;
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

