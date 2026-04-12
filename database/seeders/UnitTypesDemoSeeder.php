<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Condominium;
use App\\Modules\\Core\\Models\\UnitType;
use Illuminate\Database\Seeder;

class UnitTypesDemoSeeder extends Seeder
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

        UnitType::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'UT26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            UnitType::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'UT26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder UnitTypesDemoSeeder ejecutado: 12 tipos de unidad demo creados.');
    }
}

