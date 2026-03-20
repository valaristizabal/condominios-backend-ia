<?php

namespace Database\Seeders;

use App\Models\Condominium;
use App\Models\Inventory;
use Illuminate\Database\Seeder;

class InventoryLocationsDemoSeeder extends Seeder
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

        Inventory::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'UBI26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            Inventory::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'UBI26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder InventoryLocationsDemoSeeder ejecutado: 12 ubicaciones de inventario demo creadas.');
    }
}
