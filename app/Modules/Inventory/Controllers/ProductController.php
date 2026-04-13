<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Providers\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'inventory_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $query = Product::query()
            ->with([
                'inventory:id,condominium_id,name',
                'inventoryCategory:id,condominium_id,name,is_active',
                'supplier:id,condominium_id,name,is_active',
            ])
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

        $products = $query->paginate((int) ($validated['per_page'] ?? 10));
        $products->getCollection()->transform(fn (Product $product) => $this->serializeProduct($product));

        return response()->json($products);
    }

    public function productsWithMovements(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'inventory_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $query = Product::query()
            ->with([
                'inventory:id,condominium_id,name',
                'inventoryCategory:id,condominium_id,name,is_active',
                'supplier:id,condominium_id,name,is_active',
            ])
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

        $products = $query->paginate((int) ($validated['per_page'] ?? 10));
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
        $this->ensureConfigurationDependenciesExist($activeCondominiumId);

        $validated = $request->validate([
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:inventory_categories,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'unit_measure' => ['nullable', 'string', 'max:50'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
            'type' => ['required', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'asset_code' => ['nullable', 'string', 'max:100'],
            'serial' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
        ], $this->productValidationMessages());

        $this->resolveInventoryInActiveCondominium((int) $validated['inventory_id'], $activeCondominiumId);
        $category = $this->resolveCategoryInActiveCondominium($validated, $activeCondominiumId);
        $supplier = $this->resolveSupplierInActiveCondominium($validated, $activeCondominiumId);

        $type = (string) $validated['type'];
        $this->validateProductPayload($validated, $type, $activeCondominiumId);
        $serial = $type === Product::TYPE_ASSET ? $this->normalizeSerial($validated['serial'] ?? null, true) : null;
        $this->ensureSerialAvailable($serial, $activeCondominiumId);
        $this->ensureAssetCodeAvailable($type === Product::TYPE_ASSET ? ($validated['asset_code'] ?? null) : null, $activeCondominiumId);

        $product = Product::query()->create([
            'inventory_id' => (int) $validated['inventory_id'],
            'category_id' => $category?->id,
            'supplier_id' => $supplier?->id,
            'name' => trim($validated['name']),
            'category' => $category?->name,
            'unit_measure' => $validated['unit_measure'] ?? null,
            'unit_cost' => $validated['unit_cost'] ?? null,
            'stock' => $type === Product::TYPE_ASSET ? 1 : (int) ($validated['stock'] ?? 0),
            'minimum_stock' => $type === Product::TYPE_CONSUMABLE ? (int) ($validated['minimum_stock'] ?? 0) : 0,
            'type' => $type,
            'asset_code' => $type === Product::TYPE_ASSET ? ($validated['asset_code'] ?? null) : null,
            'serial' => $serial,
            'location' => $type === Product::TYPE_ASSET ? ($validated['location'] ?? null) : null,
            'is_active' => (bool) $validated['is_active'],
            'responsible_id' => $validated['responsible_id'] ?? null,
        ]);

        return response()->json($this->serializeProduct($product->fresh()->load([
            'inventory:id,condominium_id,name',
            'inventoryCategory:id,condominium_id,name,is_active',
            'supplier:id,condominium_id,name,is_active',
        ])), 201);
    }

    public function import(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);
        $this->ensureConfigurationDependenciesExist($activeCondominiumId);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $file = $validated['file'];
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible leer el archivo CSV cargado.'],
            ]);
        }

        [$totalRows, $successfulRows, $failedRows] = $this->importCsvFile($file, $activeCondominiumId);

        return response()->json([
            'total_filas' => $totalRows,
            'registros_exitosos' => $successfulRows,
            'registros_fallidos' => $failedRows,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $product = $this->resolveProductInActiveCondominium($id, $activeCondominiumId);

        $validated = $request->validate([
            'inventory_id' => ['sometimes', 'integer', 'exists:inventories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', 'exists:inventory_categories,id'],
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'unit_measure' => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'minimum_stock' => ['sometimes', 'integer', 'min:0'],
            'type' => ['sometimes', Rule::in([Product::TYPE_CONSUMABLE, Product::TYPE_ASSET])],
            'asset_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'responsible_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ], $this->productValidationMessages());

        if (array_key_exists('inventory_id', $validated)) {
            $this->resolveInventoryInActiveCondominium((int) $validated['inventory_id'], $activeCondominiumId);
        }

        $category = $this->resolveCategoryInActiveCondominium($validated, $activeCondominiumId);
        if (array_key_exists('category_id', $validated)) {
            $validated['category'] = $category?->name;
        }
        $supplier = $this->resolveSupplierInActiveCondominium($validated, $activeCondominiumId);
        if (array_key_exists('supplier_id', $validated)) {
            $validated['supplier_id'] = $supplier?->id;
        }

        $type = (string) ($validated['type'] ?? $product->type ?? Product::TYPE_CONSUMABLE);
        $mergedPayload = [
            'inventory_id' => $validated['inventory_id'] ?? $product->inventory_id,
            'name' => array_key_exists('name', $validated) ? $validated['name'] : $product->name,
            'category_id' => array_key_exists('category_id', $validated) ? $validated['category_id'] : $product->category_id,
            'supplier_id' => array_key_exists('supplier_id', $validated) ? $validated['supplier_id'] : $product->supplier_id,
            'unit_cost' => array_key_exists('unit_cost', $validated) ? $validated['unit_cost'] : $product->unit_cost,
            'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $product->is_active,
            'stock' => array_key_exists('stock', $validated) ? $validated['stock'] : $product->stock,
            'minimum_stock' => array_key_exists('minimum_stock', $validated) ? $validated['minimum_stock'] : $product->minimum_stock,
            'asset_code' => array_key_exists('asset_code', $validated) ? $validated['asset_code'] : $product->asset_code,
            'location' => array_key_exists('location', $validated) ? $validated['location'] : $product->location,
        ];
        $this->validateProductPayload($mergedPayload, $type, $activeCondominiumId);

        if ($type === Product::TYPE_CONSUMABLE) {
            $validated['asset_code'] = null;
            $validated['serial'] = null;
            $validated['location'] = null;
        } else {
            $validated['stock'] = 1;
            $validated['minimum_stock'] = 0;
        }

        if ($type === Product::TYPE_ASSET || array_key_exists('serial', $validated)) {
            $validated['serial'] = $this->normalizeSerial(
                $validated['serial'] ?? $product->serial,
                $type === Product::TYPE_ASSET
            );
            $this->ensureSerialAvailable($validated['serial'], $activeCondominiumId, $product->id);
        }

        $this->ensureAssetCodeAvailable(
            $type === Product::TYPE_ASSET ? ($validated['asset_code'] ?? $product->asset_code) : null,
            $activeCondominiumId,
            $product->id
        );

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }

        $product->update($validated);

        return response()->json($this->serializeProduct($product->fresh()->load([
            'inventory:id,condominium_id,name',
            'inventoryCategory:id,condominium_id,name,is_active',
            'supplier:id,condominium_id,name,is_active',
        ])));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $product = $this->resolveProductInActiveCondominium($id, $activeCondominiumId);
        $product->update(['is_active' => false]);

        return response()->json([
            'message' => 'Producto desactivado.',
            'data' => $this->serializeProduct($product->fresh()->load([
                'inventory:id,condominium_id,name',
                'inventoryCategory:id,condominium_id,name,is_active',
                'supplier:id,condominium_id,name,is_active',
            ])),
        ]);
    }

    public function deactivateAsset(Request $request, int $id): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $product = $this->resolveProductInActiveCondominium($id, $activeCondominiumId);

        if (! $product->isAsset()) {
            throw ValidationException::withMessages([
                'product_id' => ['Solo los activos fijos pueden darse de baja por este endpoint.'],
            ]);
        }

        if ($product->dado_de_baja) {
            throw ValidationException::withMessages([
                'product_id' => ['El activo fijo ya fue dado de baja.'],
            ]);
        }

        $product->update([
            'dado_de_baja' => true,
            'dado_de_baja_por' => $request->user()?->id,
            'fecha_baja' => Carbon::now(),
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Activo fijo dado de baja.',
            'data' => $this->serializeProduct($product->fresh()->load([
                'inventory:id,condominium_id,name',
                'inventoryCategory:id,condominium_id,name,is_active',
                'supplier:id,condominium_id,name,is_active',
                'deactivatedBy:id,full_name,email,document_number',
            ])),
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $activeCondominiumId = $this->activeCondominium($request);
        $this->rejectCondominiumIdFromRequest($request);

        $products = Product::query()
            ->with([
                'inventory:id,condominium_id,name',
                'inventoryCategory:id,condominium_id,name,is_active',
                'supplier:id,condominium_id,name,is_active',
            ])
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
            'supplier_id' => $product->supplier_id,
            'supplier' => $product->supplier,
            'unit_measure' => $product->unit_measure,
            'unit_cost' => $product->unit_cost !== null ? (float) $product->unit_cost : null,
            'total_value' => $product->total_value !== null ? (float) $product->total_value : null,
            'type' => $product->type,
            'asset_code' => $product->asset_code,
            'serial' => $product->serial,
            'location' => $product->location,
            'stock' => (int) $product->stock,
            'stock_actual' => (int) $product->stock,
            'minimum_stock' => (int) $product->minimum_stock,
            'is_below_minimum_stock' => $product->isBelowMinimumStock(),
            'is_active' => (bool) $product->is_active,
            'dado_de_baja' => (bool) $product->dado_de_baja,
            'dado_de_baja_por' => $product->dado_de_baja_por,
            'fecha_baja' => $product->fecha_baja,
            'deactivated_by' => $product->relationLoaded('deactivatedBy') ? $product->deactivatedBy : null,
            'responsible_id' => $product->responsible_id,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];
    }

    private function normalizeSerial(?string $serial, bool $required): ?string
    {
        $normalized = trim((string) $serial);

        if ($normalized === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    'serial' => ['El serial es obligatorio para activos fijos.'],
                ]);
            }

            return null;
        }

        return $normalized;
    }

    private function ensureAssetCodeAvailable(?string $assetCode, int $activeCondominiumId, ?int $ignoreProductId = null): void
    {
        $normalized = trim((string) $assetCode);

        if ($normalized === '') {
            return;
        }

        $exists = Product::query()
            ->where('asset_code', $normalized)
            ->when($ignoreProductId, fn ($query) => $query->where('id', '!=', $ignoreProductId))
            ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'asset_code' => ['Ya existe un activo fijo con este código en el condominio activo.'],
            ]);
        }
    }

    private function validateProductPayload(array $payload, string $type, int $activeCondominiumId): void
    {
        $errors = [];

        if (trim((string) ($payload['name'] ?? '')) === '') {
            $errors['name'] = ['El nombre es obligatorio.'];
        }

        if (empty($payload['category_id'])) {
            $errors['category_id'] = ['La categoría es obligatoria.'];
        }

        if (empty($payload['supplier_id'])) {
            $errors['supplier_id'] = ['El proveedor es obligatorio.'];
        }

        if (empty($payload['inventory_id'])) {
            $errors['inventory_id'] = ['La ubicación de inventario es obligatoria.'];
        }

        if (! array_key_exists('unit_cost', $payload) || $payload['unit_cost'] === null || $payload['unit_cost'] === '') {
            $errors['unit_cost'] = ['El costo unitario es obligatorio.'];
        }

        if (! array_key_exists('is_active', $payload) || $payload['is_active'] === null || $payload['is_active'] === '') {
            $errors['is_active'] = ['El estado activo es obligatorio.'];
        }

        if ($type === Product::TYPE_CONSUMABLE) {
            if (! array_key_exists('stock', $payload) || $payload['stock'] === null || $payload['stock'] === '') {
                $errors['stock'] = ['Debe ingresar el stock para productos consumibles.'];
            }

            if (! array_key_exists('minimum_stock', $payload) || $payload['minimum_stock'] === null || $payload['minimum_stock'] === '') {
                $errors['minimum_stock'] = ['Debe ingresar el stock mínimo de alerta para productos consumibles.'];
            }
        }

        if ($type === Product::TYPE_ASSET) {
            if (trim((string) ($payload['asset_code'] ?? '')) === '') {
                $errors['asset_code'] = ['El código de activo es obligatorio para activos fijos.'];
            }

            if (trim((string) ($payload['location'] ?? '')) === '') {
                $errors['location'] = ['La ubicación es obligatoria para activos fijos.'];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function productValidationMessages(): array
    {
        return [
            'inventory_id.required' => 'La ubicación de inventario es obligatoria.',
            'name.required' => 'El nombre es obligatorio.',
            'category_id.required' => 'La categoría es obligatoria.',
            'supplier_id.required' => 'El proveedor es obligatorio.',
            'unit_cost.required' => 'El costo unitario es obligatorio.',
            'type.required' => 'El tipo es obligatorio.',
            'is_active.required' => 'El estado activo es obligatorio.',
        ];
    }

    private function ensureSerialAvailable(?string $serial, int $activeCondominiumId, ?int $ignoreProductId = null): void
    {
        if (! $serial) {
            return;
        }

        $exists = Product::query()
            ->where('serial', $serial)
            ->when($ignoreProductId, fn ($query) => $query->where('id', '!=', $ignoreProductId))
            ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                $query->where('condominium_id', $activeCondominiumId);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'serial' => ['Ya existe un activo fijo con este serial en el condominio activo.'],
            ]);
        }
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

    private function resolveSupplierInActiveCondominium(
        array $validated,
        int $activeCondominiumId,
        bool $partial = false
    ): ?Supplier {
        if (! array_key_exists('supplier_id', $validated)) {
            return null;
        }

        if ($validated['supplier_id'] === null) {
            return null;
        }

        $supplier = Supplier::query()
            ->where('id', (int) $validated['supplier_id'])
            ->where('condominium_id', $activeCondominiumId)
            ->first();

        if (! $supplier) {
            throw ValidationException::withMessages([
                'supplier_id' => ['El proveedor no pertenece al condominio activo.'],
            ]);
        }

        if (! $supplier->is_active && ! $partial) {
            throw ValidationException::withMessages([
                'supplier_id' => ['El proveedor seleccionado esta inactivo.'],
            ]);
        }

        return $supplier;
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

    private function ensureConfigurationDependenciesExist(int $activeCondominiumId): void
    {
        $missing = [];

        $hasInventories = Inventory::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->exists();

        if (! $hasInventories) {
            $missing[] = 'ubicaciones de inventario';
        }

        $hasCategories = InventoryCategory::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->exists();

        if (! $hasCategories) {
            $missing[] = 'categorias de inventario';
        }

        $hasSuppliers = Supplier::query()
            ->where('condominium_id', $activeCondominiumId)
            ->where('is_active', true)
            ->exists();

        if (! $hasSuppliers) {
            $missing[] = 'proveedores';
        }

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'configuration' => ['Antes de crear productos debes configurar: '.implode(', ', $missing).'.'],
            ]);
        }
    }

    private function importCsvFile(UploadedFile $file, int $activeCondominiumId): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if (! $handle) {
            throw ValidationException::withMessages([
                'file' => ['No fue posible abrir el archivo CSV.'],
            ]);
        }

        try {
            $this->skipUtf8Bom($handle);

            $delimiter = $this->detectCsvDelimiter($handle);
            $header = fgetcsv($handle, 0, $delimiter);
            if (! is_array($header)) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo CSV esta vacio o no tiene encabezados validos.'],
                ]);
            }

            $normalizedHeader = array_map([$this, 'normalizeCsvHeader'], $header);
            if (! $this->hasRequiredCsvColumns($normalizedHeader)) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo debe incluir las columnas requeridas para productos.'],
                ]);
            }

            $columnIndex = array_flip($normalizedHeader);
            $inventoriesByName = Inventory::query()
                ->where('condominium_id', $activeCondominiumId)
                ->where('is_active', true)
                ->get(['id', 'name'])
                ->mapWithKeys(fn (Inventory $item) => [mb_strtolower(trim((string) $item->name)) => $item])
                ->all();
            $categoriesByName = InventoryCategory::query()
                ->where('condominium_id', $activeCondominiumId)
                ->where('is_active', true)
                ->get(['id', 'name'])
                ->mapWithKeys(fn (InventoryCategory $item) => [mb_strtolower(trim((string) $item->name)) => $item])
                ->all();
            $suppliersByName = Supplier::query()
                ->where('condominium_id', $activeCondominiumId)
                ->where('is_active', true)
                ->get(['id', 'name'])
                ->mapWithKeys(fn (Supplier $item) => [mb_strtolower(trim((string) $item->name)) => $item])
                ->all();

            $existingAssetCodes = Product::query()
                ->whereNotNull('asset_code')
                ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                    $query->where('condominium_id', $activeCondominiumId);
                })
                ->pluck('asset_code')
                ->map(fn ($code) => mb_strtolower(trim((string) $code)))
                ->filter()
                ->all();
            $existingAssetCodesLookup = array_fill_keys($existingAssetCodes, true);
            $fileAssetCodesLookup = [];
            $existingSerials = Product::query()
                ->whereNotNull('serial')
                ->whereHas('inventory', function ($query) use ($activeCondominiumId) {
                    $query->where('condominium_id', $activeCondominiumId);
                })
                ->pluck('serial')
                ->map(fn ($serial) => mb_strtolower(trim((string) $serial)))
                ->filter()
                ->all();
            $existingSerialsLookup = array_fill_keys($existingSerials, true);
            $fileSerialsLookup = [];

            $totalRows = 0;
            $successfulRows = 0;
            $failedRows = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if (! is_array($row) || $this->isCsvRowEmpty($row)) {
                    continue;
                }

                $totalRows++;

                $name = $this->csvValue($row, $columnIndex, 'nombre');
                $typeText = $this->normalizeImportedType($this->csvValue($row, $columnIndex, 'tipo'));
                $inventoryName = $this->csvValue($row, $columnIndex, 'ubicacion_inventario');
                $categoryName = $this->csvValue($row, $columnIndex, 'categoria');
                $supplierName = $this->csvValue($row, $columnIndex, 'proveedor');
                $stockText = $this->csvValue($row, $columnIndex, 'stock');
                $minimumStockText = $this->csvValue($row, $columnIndex, 'stock_minimo_alerta');
                $unitCostText = $this->csvValue($row, $columnIndex, 'costo_unitario');
                $assetCode = $this->csvValue($row, $columnIndex, 'codigo_activo');
                $serial = $this->csvValue($row, $columnIndex, 'serial');
                $location = $this->csvValue($row, $columnIndex, 'ubicacion');
                $active = $this->csvValue($row, $columnIndex, 'activo');

                $error = $this->validateImportedRow(
                    $name,
                    $typeText,
                    $inventoryName,
                    $categoryName,
                    $supplierName,
                    $unitCostText,
                    $active,
                    $stockText,
                    $minimumStockText,
                    $assetCode,
                    $serial,
                    $location,
                    $existingAssetCodesLookup,
                    $fileAssetCodesLookup,
                    $existingSerialsLookup,
                    $fileSerialsLookup,
                    $inventoriesByName,
                    $categoriesByName,
                    $suppliersByName
                );

                if ($error !== null) {
                    $failedRows[] = [
                        'fila' => $rowNumber,
                        'error' => $error,
                    ];
                    continue;
                }

                $normalizedType = $typeText === 'activo_fijo' ? Product::TYPE_ASSET : Product::TYPE_CONSUMABLE;
                $inventory = $inventoriesByName[mb_strtolower(trim($inventoryName))];
                $category = $categoriesByName[mb_strtolower(trim($categoryName))];
                $supplier = $suppliersByName[mb_strtolower(trim($supplierName))];

                if ($normalizedType === Product::TYPE_CONSUMABLE) {
                    $product = Product::query()
                        ->where('type', Product::TYPE_CONSUMABLE)
                        ->where('inventory_id', $inventory->id)
                        ->where('category_id', $category->id)
                        ->where('supplier_id', $supplier->id)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
                        ->first();

                    if ($product) {
                        $product->update([
                            'category' => $category->name,
                            'unit_cost' => (float) $unitCostText,
                            'stock' => (int) $stockText,
                            'minimum_stock' => (int) $minimumStockText,
                            'is_active' => $active === '1',
                        ]);
                    } else {
                        Product::query()->create([
                            'inventory_id' => $inventory->id,
                            'category_id' => $category->id,
                            'supplier_id' => $supplier->id,
                            'name' => trim($name),
                            'category' => $category->name,
                            'unit_cost' => (float) $unitCostText,
                            'stock' => (int) $stockText,
                            'minimum_stock' => (int) $minimumStockText,
                            'type' => Product::TYPE_CONSUMABLE,
                            'asset_code' => null,
                            'serial' => null,
                            'location' => null,
                            'is_active' => $active === '1',
                        ]);
                    }
                } else {
                    Product::query()->create([
                        'inventory_id' => $inventory->id,
                        'category_id' => $category->id,
                        'supplier_id' => $supplier->id,
                        'name' => trim($name),
                        'category' => $category->name,
                        'unit_cost' => (float) $unitCostText,
                        'stock' => 1,
                        'minimum_stock' => 0,
                        'type' => Product::TYPE_ASSET,
                        'asset_code' => trim($assetCode),
                        'serial' => trim($serial),
                        'location' => trim($location),
                        'is_active' => $active === '1',
                    ]);
                }

                if ($normalizedType === Product::TYPE_ASSET) {
                    $normalizedAssetCode = mb_strtolower(trim($assetCode));
                    $normalizedSerial = mb_strtolower(trim($serial));
                    $fileAssetCodesLookup[$normalizedAssetCode] = true;
                    $existingAssetCodesLookup[$normalizedAssetCode] = true;
                    $fileSerialsLookup[$normalizedSerial] = true;
                    $existingSerialsLookup[$normalizedSerial] = true;
                }

                $successfulRows++;
            }

            return [$totalRows, $successfulRows, $failedRows];
        } finally {
            fclose($handle);
        }
    }

    private function validateImportedRow(
        string $name,
        string $typeText,
        string $inventoryName,
        string $categoryName,
        string $supplierName,
        string $unitCostText,
        string $active,
        string $stockText,
        string $minimumStockText,
        string $assetCode,
        string $serial,
        string $location,
        array $existingAssetCodesLookup,
        array $fileAssetCodesLookup,
        array $existingSerialsLookup,
        array $fileSerialsLookup,
        array $inventoriesByName,
        array $categoriesByName,
        array $suppliersByName
    ): ?string {
        if (
            $name === '' ||
            $typeText === '' ||
            $inventoryName === '' ||
            $categoryName === '' ||
            $supplierName === '' ||
            $unitCostText === '' ||
            $active === ''
        ) {
            return 'nombre, tipo, ubicacion_inventario, categoria, proveedor, costo_unitario y activo son obligatorios.';
        }

        if (! in_array($typeText, ['consumible', 'activo_fijo'], true)) {
            return 'tipo solo admite los valores consumible o activo_fijo.';
        }

        if ($active !== '0' && $active !== '1') {
            return 'activo solo admite valores 1 o 0.';
        }

        if (filter_var($unitCostText, FILTER_VALIDATE_FLOAT) === false || (float) $unitCostText < 0) {
            return 'costo_unitario debe ser un numero mayor o igual a 0.';
        }

        if (! isset($inventoriesByName[mb_strtolower(trim($inventoryName))])) {
            return "ubicacion_inventario '{$inventoryName}' no existe.";
        }

        if (! isset($categoriesByName[mb_strtolower(trim($categoryName))])) {
            return "categoria '{$categoryName}' no existe.";
        }

        if (! isset($suppliersByName[mb_strtolower(trim($supplierName))])) {
            return "proveedor '{$supplierName}' no existe.";
        }

        if ($typeText === 'consumible') {
            if ($stockText === '' || $minimumStockText === '') {
                return 'stock y stock_minimo_alerta son obligatorios para consumibles.';
            }

            if (filter_var($stockText, FILTER_VALIDATE_INT) === false || (int) $stockText < 0) {
                return 'stock debe ser un entero mayor o igual a 0.';
            }

            if (filter_var($minimumStockText, FILTER_VALIDATE_INT) === false || (int) $minimumStockText < 0) {
                return 'stock_minimo_alerta debe ser un entero mayor o igual a 0.';
            }

            return null;
        }

        if ($assetCode === '' || $serial === '' || $location === '') {
            return 'codigo_activo, serial y ubicacion son obligatorios para activo_fijo.';
        }

        $normalizedAssetCode = mb_strtolower(trim($assetCode));
        if (isset($existingAssetCodesLookup[$normalizedAssetCode])) {
            return "codigo_activo '{$assetCode}' ya existe en la base de datos.";
        }

        if (isset($fileAssetCodesLookup[$normalizedAssetCode])) {
            return "codigo_activo '{$assetCode}' esta repetido dentro del archivo.";
        }

        $normalizedSerial = mb_strtolower(trim($serial));
        if (isset($existingSerialsLookup[$normalizedSerial])) {
            return "serial '{$serial}' ya existe en la base de datos.";
        }

        if (isset($fileSerialsLookup[$normalizedSerial])) {
            return "serial '{$serial}' esta repetido dentro del archivo.";
        }

        return null;
    }

    private function hasRequiredCsvColumns(array $header): bool
    {
        $required = [
            'nombre',
            'tipo',
            'ubicacion_inventario',
            'categoria',
            'proveedor',
            'stock',
            'stock_minimo_alerta',
            'costo_unitario',
            'codigo_activo',
            'serial',
            'ubicacion',
            'activo',
        ];

        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeImportedType(string $typeText): string
    {
        $normalized = mb_strtolower(trim($typeText));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'consumible', Product::TYPE_CONSUMABLE => 'consumible',
            'activo_fijo', 'activofijo', Product::TYPE_ASSET => 'activo_fijo',
            default => $normalized,
        };
    }

    private function csvValue(array $row, array $columnIndex, string $column): string
    {
        $index = $columnIndex[$column] ?? null;
        if ($index === null) {
            return '';
        }

        return $this->normalizeCsvCell($row[$index] ?? '');
    }

    private function normalizeCsvCell(mixed $value): string
    {
        $text = (string) $value;

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

        return trim($text);
    }

    private function normalizeCsvHeader(mixed $value): string
    {
        $text = $this->normalizeCsvCell($value);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', '_', $text) ?? $text;

        return trim($text, '_');
    }

    private function isCsvRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeCsvCell($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function skipUtf8Bom($handle): void
    {
        $bom = "\xEF\xBB\xBF";
        $firstBytes = fread($handle, 3);

        if ($firstBytes !== $bom) {
            rewind($handle);
        }
    }

    private function detectCsvDelimiter($handle): string
    {
        $position = ftell($handle);
        $sample = fgets($handle);

        if ($sample === false) {
            fseek($handle, $position);
            return ',';
        }

        $commaCount = substr_count($sample, ',');
        $semicolonCount = substr_count($sample, ';');

        fseek($handle, $position);

        return $semicolonCount > $commaCount ? ';' : ',';
    }
}




