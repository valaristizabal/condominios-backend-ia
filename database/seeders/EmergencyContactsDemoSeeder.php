<?php

namespace Database\Seeders;

use App\Models\Condominium;
use App\Models\EmergencyContact;
use Illuminate\Database\Seeder;

class EmergencyContactsDemoSeeder extends Seeder
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

        EmergencyContact::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'CE26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            EmergencyContact::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'CE26-' . $suffix,
                'phone_number' => '60' . str_pad((string) (10000000 + $index), 8, '0', STR_PAD_LEFT),
                'icon' => 'phone',
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder EmergencyContactsDemoSeeder ejecutado: 12 contactos de emergencia demo creados.');
    }
}
