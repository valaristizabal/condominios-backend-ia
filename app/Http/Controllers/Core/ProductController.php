<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryCategory;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'inventory_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()
            ->with(['inventory:id,condominium_id,name', 'inventoryCategory:id,condominium_id,name,is_active'])
            ->whereHas('inventory', function ($inventoryQuery) use ($activeCondominiumId) {
                $inventoryQuery->where('condominium_id', $activeCondominiumId);
            })
            ->orderBy('name');

        if (! empty($validated['inventory_id'])) {
            $query->where('inventory_id', (int) $validated['inventory_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $products = $query->paginate((int) ($validated['per_page'] ?? 20));
        $products->getCollection()->transform(fn (Product $product) => $this->serializeProduct($product));

        return response()->json($products);
    }

    public function productsWithMovements(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'inventory_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()
            ->with(['inventory:id,condominium_id,name', 'inventoryCategory:id,condominium_id,name,is_active'])
            ->whereHas('inventory', function ($inventoryQuery) use ($activeCondominiumId) {
                $inventoryQuery->where('condominium_id', $activeCondominiumId);
            })
            ->orderBy('name');

        if (! empty($validated['inventory_id'])) {
            $query->where('inventory_id', (int) $validated['inventory_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $products = $query->paginate((int) ($validated['per_page'] ?? 20));
        $productIds = $products->getCollection()->pluck('id')->all();

        $movementsByProduct = collect();
        if (! empty($productIds)) {
            $movementsByProduct = InventoryMovement::query()
                ->with(['registeredBy:id,full_name,email,document_number'])
                ->whereIn('product_id', $productIds)
                ->orderByDesc('movement_date')
                ->orderByDesc('id')
                ->get()
                ->groupBy('product_id')
                ->map(fn ($rows) => $rows->take(5)->values());
        }

        $products->getCollection()->transform(function (Product $product) use ($movementsByProduct) {
            return [
                ...$this->serializeProduct($product),
                'last_movements' => ($movementsByProduct->get($product->id) ?? collect())->values(),
            ];
        });

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'unit_measure' => ['nullable', 'string', 'max:50'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
            'type' => ['nullable', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'asset_code' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->resolveInventoryInActiveCondominium((int) $validated['inventory_id'], $activeCondominiumId);
        $category = $this->resolveCategoryInActiveCondominium($validated, $activeCondominiumId);

        $type = (string) ($validated['type'] ?? Product::TYPE_CONSUMABLE);
        $product = Product::query()->create([
            'inventory_id' => (int) $validated['inventory_id'],
            'category_id' => $category?->id,
            'name' => trim($validated['name']),
            'category' => $category?->name,
            'unit_measure' => $validated['unit_measure'] ?? null,
            'unit_cost' => $validated['unit_cost'] ?? null,
            'stock' => (int) ($validated['stock'] ?? 0),
            'minimum_stock' => (int) ($validated['minimum_stock'] ?? 0),
            'type' => $type,
            'asset_code' => $type === Product::TYPE_ASSET ? ($validated['asset_code'] ?? null) : null,
            'location' => $validated['location'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'responsible_id' => $validated['responsible_id'] ?? null,
        ]);

        return response()->json($this->serializeProduct($product->fresh()->load(['inventory:id,condominium_id,name', 'inventoryCategory:id,condominium_id,name,is_active'])), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $product = $this->resolveProductInActiveCondominium($id, $activeCondominiumId);

        $validated = $request->validate([
            'inventory_id' => ['sometimes', 'integer', 'exists:inventories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:inventory_categories,id'],
            'unit_measure' => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'minimum_stock' => ['sometimes', 'integer', 'min:0'],
            'type' => ['sometimes', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'asset_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'responsible_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        if (array_key_exists('inventory_id', $validated)) {
            $this->resolveInventoryInActiveCondominium((int) $validated['inventory_id'], $activeCondominiumId);
        }

        $category = $this->resolveCategoryInActiveCondominium($validated, $activeCondominiumId, true);
        if (array_key_exists('category_id', $validated)) {
            $validated['category'] = $category?->name;
        }

        $type = (string) ($validated['type'] ?? $product->type ?? Product::TYPE_CONSUMABLE);
        if ($type === Product::TYPE_CONSUMABLE && array_key_exists('asset_code', $validated) && $validated['asset_code'] === null) {
            $validated['asset_code'] = null;
        }
        if ($type === Product::TYPE_CONSUMABLE && ! array_key_exists('asset_code', $validated)) {
            $validated['asset_code'] = null;
        }

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }

        $product->update($validated);

        return response()->json($this->serializeProduct($product->fresh()->load(['inventory:id,condominium_id,name', 'inventoryCategory:id,condominium_id,name,is_active'])));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $product = $this->resolveProductInActiveCondominium($id, $activeCondominiumId);
        $product->update(['is_active' => false]);

        return response()->json([
            'message' => 'Producto desactivado.',
            'data' => $this->serializeProduct($product->fresh()->load(['inventory:id,condominium_id,name', 'inventoryCategory:id,condominium_id,name,is_active'])),
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $products = Product::query()
            ->with(['inventory:id,condominium_id,name', 'inventoryCategory:id,condominium_id,name,is_active'])
            ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->where('type', Product::TYPE_CONSUMABLE)
            ->where('is_active', true)
            ->whereColumn('stock', '<=', 'minimum_stock')
            ->orderBy('stock')
            ->orderBy('name')
            ->get();

        return response()->json(
            $products->map(fn (Product $product) => $this->serializeProduct($product))
        );
    }

    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'inventory_id' => $product->inventory_id,
            'inventory' => $product->inventory,
            'name' => $product->name,
            'category_id' => $product->category_id,
            'category' => $product->inventoryCategory?->name ?? $product->category,
            'inventory_category' => $product->inventoryCategory,
            'unit_measure' => $product->unit_measure,
            'unit_cost' => $product->unit_cost,
            'type' => $product->type,
            'asset_code' => $product->asset_code,
            'location' => $product->location,
            'stock' => (int) $product->stock,
            'stock_actual' => (int) $product->stock,
            'minimum_stock' => (int) $product->minimum_stock,
            'is_below_minimum_stock' => $product->isBelowMinimumStock(),
            'is_active' => (bool) $product->is_active,
            'responsible_id' => $product->responsible_id,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];
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

    private function resolveInventoryInActiveCondominium(int $inventoryId, int $activeCondominiumId): Inventory
    {
        $inventory = Inventory::query()
            ->where('id', $inventoryId)
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $inventory) {
            throw ValidationException::withMessages([
                'inventory_id' => ['El inventario no pertenece al condominio activo.'],
            ]);
        }

        return $inventory;
    }

    private function resolveCategoryInActiveCondominium(
        array $validated,
        int $activeCondominiumId,
        bool $partial = false
    ): ?InventoryCategory {
        if (! array_key_exists('category_id', $validated)) {
            return null;
        }

        if ($validated['category_id'] === null) {
            return null;
        }

        $category = InventoryCategory::query()
            ->where('id', (int) $validated['category_id'])
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => ['La categoria no pertenece al condominio activo.'],
            ]);
        }

        if (! $category->is_active && ! $partial) {
            throw ValidationException::withMessages([
                'category_id' => ['La categoria seleccionada esta inactiva.'],
            ]);
        }

        return $category;
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
