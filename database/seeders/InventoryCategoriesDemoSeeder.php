<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Inventory\Models\InventoryCategory;
use Illuminate\Database\Seeder;

class InventoryCategoriesDemoSeeder extends Seeder
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

        InventoryCategory::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'CAT26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            InventoryCategory::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'CAT26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder InventoryCategoriesDemoSeeder ejecutado: 12 categorias de inventario demo creadas.');
    }
}


