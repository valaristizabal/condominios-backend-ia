<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Apartment;
use App\\Modules\\Core\\Models\\Condominium;
use App\\Modules\\Core\\Models\\UnitType;
use Illuminate\Database\Seeder;

class ApartmentsDemoSeeder extends Seeder
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

        $unitTypes = UnitType::query()
            ->where('condominium_id', $condominium->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($unitTypes->isEmpty()) {
            $this->command?->warn('No hay tipos de unidad activos para crear inmuebles demo.');
            return;
        }

        Apartment::query()
            ->where('condominium_id', $condominium->id)
            ->where('number', 'like', 'INM26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            $unitType = $unitTypes[($index - 1) % $unitTypes->count()];

            Apartment::query()->create([
                'condominium_id' => $condominium->id,
                'unit_type_id' => $unitType->id,
                'tower' => 'Torre ' . chr(64 + (($index - 1) % 4) + 1),
                'number' => 'INM26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'floor' => 1 + (($index - 1) % 8),
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder ApartmentsDemoSeeder ejecutado: 12 inmuebles demo creados.');
    }
}

