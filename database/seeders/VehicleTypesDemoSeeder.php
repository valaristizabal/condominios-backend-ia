<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Vehicles\Models\VehicleType;
use Illuminate\Database\Seeder;

class VehicleTypesDemoSeeder extends Seeder
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

        VehicleType::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'TV26-%')
            ->delete();

        for ($index = 1; $index <= 24; $index++) {
            VehicleType::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'TV26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'is_active' => $index % 5 !== 0,
            ]);
        }

        $this->command?->info('Seeder VehicleTypesDemoSeeder ejecutado: 24 tipos de vehiculo demo creados.');
    }
}


