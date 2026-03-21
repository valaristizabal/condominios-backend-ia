<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'inventory_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $movements = InventoryMovement::query()
            ->with([
                'registeredBy:id,full_name,email,document_number',
                'product.inventory:id,condominium_id,name',
            ])
            ->whereHas('product.inventory', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->when(
                ! empty($validated['inventory_id']),
                fn ($query) => $query->whereHas('product', fn ($productQuery) => $productQuery->where('inventory_id', (int) $validated['inventory_id']))
            )
            ->when(
                ! empty($validated['product_id']),
                fn ($query) => $query->where('product_id', (int) $validated['product_id'])
            )
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (InventoryMovement $movement) {
                $data = $movement->toArray();
                $data['product_name'] = $movement->product?->name;
                $data['inventory_name'] = $movement->product?->inventory?->name;

                return $data;
            });

        return response()->json($movements);
    }

    public function entry(Request $request): JsonResponse
    {
        return $this->registerMovement($request, 'entry');
    }

    public function exit(Request $request): JsonResponse
    {
        return $this->registerMovement($request, 'exit');
    }

    public function historyByProduct(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $product = $this->resolveProductInActiveCondominium($id, $activeCondominiumId);

        $movements = InventoryMovement::query()
            ->with(['registeredBy:id,full_name,email,document_number'])
            ->where('product_id', $product->id)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get();

        return response()->json($movements);
    }

    private function registerMovement(Request $request, string $movementType): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'movement_date' => ['nullable', 'date'],
            'observations' => ['nullable', 'string'],
        ]);

        $movement = DB::transaction(function () use ($validated, $request, $movementType, $activeCondominiumId) {
            $product = Product::query()
                ->where('id', (int) $validated['product_id'])
                ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                    $query->where('condominium_id', $activeCondominiumId);
                })
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    'product_id' => ['El producto no pertenece al condominio activo.'],
                ]);
            }

            if ($product->isInactiveAsset()) {
                throw ValidationException::withMessages([
                    'product_id' => ['El activo fijo ya fue dado de baja.'],
                ]);
            }

            $quantity = (int) $validated['quantity'];
            if ($product->isAsset()) {
                if ($movementType !== 'exit') {
                    throw ValidationException::withMessages([
                        'type' => ['Los activos fijos solo admiten movimientos de salida individual.'],
                    ]);
                }

                if ($quantity !== 1) {
                    throw ValidationException::withMessages([
                        'quantity' => ['Los activos fijos solo se manejan de forma individual (cantidad = 1).'],
                    ]);
                }
            }

            if ($movementType === 'exit' && $product->isConsumable() && (int) $product->stock < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['No hay stock suficiente para registrar esta salida.'],
                ]);
            }

            return InventoryMovement::query()->create([
                'product_id' => $product->id,
                'type' => $movementType,
                'quantity' => $quantity,
                'movement_date' => $validated['movement_date'] ?? null,
                'observations' => $validated['observations'] ?? null,
                'registered_by_id' => $request->user()?->id,
            ]);
        });

        $product = Product::query()
            ->where('id', $movement->product_id)
            ->first();

        return response()->json([
            'movement' => $movement->fresh(),
            'stock_actual' => $product ? (int) $product->stock : 0,
            'minimum_stock' => $product ? (int) $product->minimum_stock : 0,
            'total_value' => $product && $product->total_value !== null ? (float) $product->total_value : null,
            'is_below_minimum_stock' => $product ? $product->isBelowMinimumStock() : false,
        ], 201);
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

    private function resolveProductInActiveCondominium(int $productId, int $activeCondominiumId): Product
    {
        $product = Product::query()
            ->where('id', $productId)
            ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => ['El producto no pertenece al condominio activo.'],
            ]);
        }

        return $product;
    }
}
