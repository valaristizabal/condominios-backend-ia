<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\EmployeeEntry;
use App\Modules\Core\Models\Operative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeEntryController extends Controller
{
    private const STATUS_ACTIVE = 'active';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_CANCELLED = 'cancelled';


    public function bootstrapData(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $operatives = Operative::query()
            ->with(['user:id,full_name,email,document_number'])
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->orderBy('contract_type')
            ->orderBy('position')
            ->orderByDesc('id')
            ->get([
                'id',
                'user_id',
                'condominium_id',
                'position',
                'contract_type',
                'is_active',
            ])
            ->map(fn (Operative $operative) => [
                'id' => $operative->id,
                'user_id' => $operative->user_id,
                'condominium_id' => $operative->condominium_id,
                'position' => $operative->position,
                'contract_type' => $operative->contract_type,
                'is_active' => (bool) $operative->is_active,
                'user' => $operative->user ? [
                    'id' => $operative->user->id,
                    'full_name' => $operative->user->full_name,
                    'email' => $operative->user->email,
                    'document_number' => $operative->user->document_number,
                ] : null,
            ])
            ->values();

        return response()->json([
            'operatives' => $operatives,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);
        $validated = $request->validate([
            'active_page' => ['nullable', 'integer', 'min:1'],
            'history_page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $activePage = (int) ($validated['active_page'] ?? 1);
        $historyPage = (int) ($validated['history_page'] ?? 1);

        $baseQuery = EmployeeEntry::query()
            ->with([
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
            ])
            ->where('condominium_id', $activeCondominiumId)
            ->orderByDesc('id');

        $activeEntries = (clone $baseQuery)
            ->where('status', self::STATUS_ACTIVE)
            ->orderByDesc('check_in_at')
            ->paginate($perPage, ['*'], 'active_page', $activePage);

        $historyEntries = (clone $baseQuery)
            ->where('status', self::STATUS_COMPLETED)
            ->orderByDesc('check_out_at')
            ->paginate($perPage, ['*'], 'history_page', $historyPage);

        return response()->json([
            'active_entries' => $activeEntries,
            'history_entries' => $historyEntries,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'operative_id' => ['required', 'integer', 'exists:operatives,id'],
            'observations' => ['nullable', 'string'],
        ]);

        $operative = $this->resolveOperativeInActiveCondominium((int) $validated['operative_id'], $activeCondominiumId);

        if (! (bool) $operative->is_active) {
            throw ValidationException::withMessages([
                'operative_id' => ['El operario seleccionado no esta activo.'],
            ]);
        }

        $hasActiveEntry = EmployeeEntry::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('operative_id', $operative->id)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('check_out_at')
            ->exists();

        if ($hasActiveEntry) {
            throw ValidationException::withMessages([
                'operative_id' => ['El operario ya tiene un ingreso activo.'],
            ]);
        }

        $entry = EmployeeEntry::query()->create([
            'condominium_id' => $activeCondominiumId,
            'operative_id' => $operative->id,
            'registered_by_id' => $request->user()?->id,
            'check_in_at' => now(),
            'check_out_at' => null,
            'status' => self::STATUS_ACTIVE,
            'observations' => $validated['observations'] ?? null,
        ]);

        return response()->json(
            $entry->fresh()->load([
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
            ]),
            201
        );
    }

    public function checkout(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $entry = EmployeeEntry::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($entry->status !== self::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => ['El registro no se encuentra en estado activo.'],
            ]);
        }

        $entry->update([
            'status' => self::STATUS_COMPLETED,
            'check_out_at' => now(),
        ]);

        return response()->json(
            $entry->fresh()->load([
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
            ])
        );
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $entry = EmployeeEntry::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('id', $id)
            ->firstOrFail();

        if ($entry->status !== self::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => ['Solo se pueden cancelar ingresos activos.'],
            ]);
        }

        $entry->update([
            'status' => self::STATUS_CANCELLED,
        ]);

        return response()->json(
            $entry->fresh()->load([
                'operative:id,user_id,condominium_id,position,is_active',
                'operative.user:id,full_name,email,document_number',
                'registeredBy:id,full_name,email,document_number',
            ])
        );
    }

    private function activeCondominium(Request $request): int
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

    private function resolveOperativeInActiveCondominium(int $operativeId, int $activeCondominiumId): Operative
    {
        $operative = Operative::query()
            ->where('id', $operativeId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $operative) {
            throw ValidationException::withMessages([
                'operative_id' => ['El operario no pertenece al condominio activo.'],
            ]);
        }

        return $operative;
    }
}




