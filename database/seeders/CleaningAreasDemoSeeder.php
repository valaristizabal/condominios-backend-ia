<?php

namespace Database\Seeders;

use App\Modules\Cleaning\Models\CleaningArea;
use App\\Modules\\Core\\Models\\Condominium;
use Illuminate\Database\Seeder;

class CleaningAreasDemoSeeder extends Seeder
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

        CleaningArea::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'ZA26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            CleaningArea::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'ZA26-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'description' => 'Area demo para pruebas de paginacion en ajustes.',
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder CleaningAreasDemoSeeder ejecutado: 12 areas de aseo demo creadas.');
    }
}


