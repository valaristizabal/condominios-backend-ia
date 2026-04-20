<?php

namespace App\Modules\Expenses\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Requests\ExpenseRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'expenseType' => ['nullable', 'string', 'max:255'],
            'paymentMethod' => ['nullable', 'string', 'max:255'],
            'query' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Expense::query()
            ->where('condominium_id', $activeCondominiumId);

        if (! empty($validated['period'])) {
            $periodStart = CarbonImmutable::createFromFormat('Y-m', (string) $validated['period'])->startOfMonth();
            $periodEnd = $periodStart->endOfMonth();

            $query->whereBetween('registered_at', [
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
            ]);
        }

        if (! empty($validated['expenseType'])) {
            $query->where('expense_type', trim((string) $validated['expenseType']));
        }

        if (! empty($validated['paymentMethod'])) {
            $query->where('payment_method', trim((string) $validated['paymentMethod']));
        }

        if (! empty($validated['query'])) {
            $search = trim((string) $validated['query']);
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('observations', 'like', '%' . $search . '%')
                    ->orWhere('registered_by', 'like', '%' . $search . '%');
            });
        }

        $rowsForKpis = (clone $query)->get(['id', 'amount', 'registered_at']);
        $totalAmount = round((float) $rowsForKpis->sum('amount'), 2);
        $totalCount = (int) $rowsForKpis->count();
        $latestExpense = $rowsForKpis
            ->sortByDesc(fn (Expense $expense) => ($expense->registered_at?->toDateString() ?? '') . '-' . $expense->id)
            ->first();
        $lastExpense = round((float) ($latestExpense?->amount ?? 0), 2);

        $expenses = $query
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'kpis' => [
                'totalAmount' => $totalAmount,
                'totalCount' => $totalCount,
                'lastExpense' => $lastExpense,
            ],
            'data' => $expenses->map(fn (Expense $expense) => $this->presentExpense($expense))->values(),
        ]);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validated();

        $supportPath = null;
        if ($request->hasFile('support')) {
            $supportPath = $request->file('support')?->store('expenses', 'public');
        }

        $expense = Expense::query()->create([
            'condominium_id' => $activeCondominiumId,
            'registered_at' => CarbonImmutable::parse((string) $validated['registeredAt'])->toDateString(),
            'expense_type' => trim((string) $validated['expenseType']),
            'amount' => round((float) $validated['amount'], 2),
            'payment_method' => trim((string) $validated['paymentMethod']),
            'observations' => $this->nullableTrim($validated['observations'] ?? null),
            'support_path' => $supportPath,
            'registered_by' => trim((string) $validated['registeredBy']),
            'status' => $supportPath ? 'con-soporte' : 'pendiente-soporte',
        ]);

        return response()->json($this->presentExpense($expense->fresh()), 201);
    }

    public function update(ExpenseRequest $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $expense = Expense::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validated();

        $payload = [];

        if (array_key_exists('registeredAt', $validated)) {
            $payload['registered_at'] = CarbonImmutable::parse((string) $validated['registeredAt'])->toDateString();
        }

        if (array_key_exists('expenseType', $validated)) {
            $payload['expense_type'] = trim((string) $validated['expenseType']);
        }

        if (array_key_exists('amount', $validated)) {
            $payload['amount'] = round((float) $validated['amount'], 2);
        }

        if (array_key_exists('paymentMethod', $validated)) {
            $payload['payment_method'] = trim((string) $validated['paymentMethod']);
        }

        if (array_key_exists('observations', $validated)) {
            $payload['observations'] = $this->nullableTrim($validated['observations']);
        }

        if (array_key_exists('registeredBy', $validated)) {
            $payload['registered_by'] = trim((string) $validated['registeredBy']);
        }

        if (($validated['removeSupport'] ?? false) === true && $expense->support_path) {
            Storage::disk('public')->delete($expense->support_path);
            $payload['support_path'] = null;
        }

        if ($request->hasFile('support')) {
            if ($expense->support_path) {
                Storage::disk('public')->delete($expense->support_path);
            }

            $payload['support_path'] = $request->file('support')?->store('expenses', 'public');
        }

        $resolvedSupportPath = $payload['support_path'] ?? $expense->support_path;
        $payload['status'] = $resolvedSupportPath ? 'con-soporte' : 'pendiente-soporte';

        $expense->update($payload);

        return response()->json($this->presentExpense($expense->fresh()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->resolveActiveCondominiumId($request);
        $this->rejectCondominiumIdFromRequest($request);

        $expense = Expense::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($expense->support_path) {
            Storage::disk('public')->delete($expense->support_path);
        }

        $expense->delete();

        return response()->json([
            'message' => 'Gasto eliminado correctamente.',
        ]);
    }

    private function presentExpense(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'registeredAt' => $expense->registered_at?->toDateString(),
            'expenseType' => (string) $expense->expense_type,
            'amount' => round((float) $expense->amount, 2),
            'paymentMethod' => (string) $expense->payment_method,
            'observations' => $expense->observations,
            'supportPath' => $expense->support_path,
            'supportName' => $this->resolveSupportName($expense->support_path),
            'supportUrl' => $this->resolveSupportUrl($expense->support_path),
            'registeredBy' => (string) $expense->registered_by,
            'status' => (string) $expense->status,
            'createdAt' => $expense->created_at?->toDateTimeString(),
            'updatedAt' => $expense->updated_at?->toDateTimeString(),
        ];
    }

    private function resolveSupportName(?string $path): string
    {
        if (! $path) {
            return '';
        }

        return basename((string) $path);
    }

    private function resolveSupportUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $normalizedPath = ltrim(trim((string) $path), '/');
        $normalizedPath = preg_replace('#^storage/app/public/#', '', $normalizedPath) ?? $normalizedPath;
        $normalizedPath = preg_replace('#^public/#', '', $normalizedPath) ?? $normalizedPath;
        $normalizedPath = preg_replace('#^storage/#', '', $normalizedPath) ?? $normalizedPath;

        if ($normalizedPath === '' || ! Storage::disk('public')->exists($normalizedPath)) {
            return null;
        }

        return asset('storage/' . $normalizedPath);
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

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
