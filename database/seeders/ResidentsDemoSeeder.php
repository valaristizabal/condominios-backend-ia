<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Apartment;
use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Residents\Models\Resident;
use App\Modules\Security\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ResidentsDemoSeeder extends Seeder
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

        $apartments = Apartment::query()
            ->where('condominium_id', $condominium->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($apartments->isEmpty()) {
            $this->command?->warn('No hay inmuebles activos para crear residentes demo.');
            return;
        }

        $demoUsers = User::query()
            ->where('email', 'like', 'residente.demo26%')
            ->get(['id']);

        if ($demoUsers->isNotEmpty()) {
            Resident::query()->whereIn('user_id', $demoUsers->pluck('id')->all())->delete();
            User::query()->whereIn('id', $demoUsers->pluck('id')->all())->delete();
        }

        for ($index = 1; $index <= 12; $index++) {
            $apartment = $apartments[($index - 1) % $apartments->count()];
            $fullName = 'Residente Demo ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            $user = User::query()->create([
                'full_name' => $fullName,
                'email' => 'residente.demo26' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '@pastorita.test',
                'document_number' => '99026' . str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                'phone' => '310900' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'password' => Hash::make('12345678'),
                'is_active' => true,
                'is_platform_admin' => false,
            ]);

            Resident::query()->create([
                'user_id' => $user->id,
                'apartment_id' => $apartment->id,
                'type' => $index % 2 === 0 ? 'arrendatario' : 'propietario',
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder ResidentsDemoSeeder ejecutado: 12 residentes demo creados.');
    }
}



