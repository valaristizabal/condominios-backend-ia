<?php

namespace Database\Seeders;

use App\Models\Condominium;
use App\Models\EmergencyType;
use Illuminate\Database\Seeder;

class EmergencyTypesDemoSeeder extends Seeder
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

        EmergencyType::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'TE26-%')
            ->delete();

        $levels = ['BAJO', 'MEDIO', 'ALTO', 'CRITICO'];

        for ($index = 1; $index <= 12; $index++) {
            EmergencyType::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'TE26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'level' => $levels[($index - 1) % count($levels)],
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder EmergencyTypesDemoSeeder ejecutado: 12 tipos de emergencia demo creados.');
    }
}
