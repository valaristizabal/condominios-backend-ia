<?php

namespace Database\Seeders;

use App\Models\Condominium;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()
            ->whereIn('name', ['Administrador Propiedad', 'administrador_propiedad', 'admin_condominio'])
            ->first();
        $condominium = Condominium::query()->where('tenant_code', 'la-pastorita')->first();

        if (! $role || ! $condominium) {
            throw new RuntimeException('No existe el rol Administrador Propiedad o el condominio la-pastorita.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'full_name' => 'Valeria Aristizabal',
                'document_number' => '1000000001',
                'password' => Hash::make('123456789'),
                'is_active' => true,
            ]
        );

        $user->roles()->syncWithoutDetaching([
            $role->id => ['condominium_id' => $condominium->id],
        ]);
    }
}
