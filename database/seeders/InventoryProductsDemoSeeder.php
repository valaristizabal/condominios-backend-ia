<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\Product;
use App\Modules\Providers\Models\Supplier;
use Illuminate\Database\Seeder;

class InventoryProductsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $condominium = Condominium::query()
            ->where('tenant_code', 'la-pastorita')
            ->first();

        if (! $condominium) {
            $this->command?->warn('No existe el condominio la-pastorita. Ejecuta seeders base primero.');
            return;
        }

        $inventory = Inventory::query()
            ->where('condominium_id', $condominium->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $inventory) {
            $this->command?->warn('No hay inventarios activos para crear productos demo.');
            return;
        }

        $category = InventoryCategory::query()
            ->where('condominium_id', $condominium->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        $supplier = Supplier::query()
            ->where('condominium_id', $condominium->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        Product::query()
            ->where('inventory_id', $inventory->id)
            ->where('name', 'like', 'INV26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            Product::query()->create([
                'inventory_id' => $inventory->id,
                'category_id' => $category?->id,
                'supplier_id' => $supplier?->id,
                'name' => 'INV26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'category' => $category?->name,
                'unit_measure' => 'unidad',
                'unit_cost' => 5000 + ($index * 750),
                'stock' => 5 + $index,
                'minimum_stock' => 3,
                'type' => Product::TYPE_CONSUMABLE,
                'asset_code' => null,
                'serial' => null,
                'location' => null,
                'is_active' => true,
            ]);
        }

        $this->command?->info('Seeder InventoryProductsDemoSeeder ejecutado: 12 productos demo creados.');
    }
}






