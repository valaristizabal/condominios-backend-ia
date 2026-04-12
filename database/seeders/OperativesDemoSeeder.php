<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Condominium;
use App\\Modules\\Core\\Models\\Operative;
use App\Modules\Security\Models\Role;
use App\Modules\Security\Models\User;
use App\Modules\Security\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OperativesDemoSeeder extends Seeder
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

        $roles = Role::query()
            ->whereIn('name', ['Seguridad', 'Aseo', 'Mantenimiento'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($roles->isEmpty()) {
            $this->command?->warn('No hay roles operativos activos para crear operativos demo.');
            return;
        }

        $demoUsers = User::query()
            ->where('email', 'like', 'operativo.demo26%')
            ->get(['id']);

        if ($demoUsers->isNotEmpty()) {
            Operative::query()
                ->where('condominium_id', $condominium->id)
                ->whereIn('user_id', $demoUsers->pluck('id')->all())
                ->delete();

            UserRole::query()
                ->where('condominium_id', $condominium->id)
                ->whereIn('user_id', $demoUsers->pluck('id')->all())
                ->delete();

            User::query()->whereIn('id', $demoUsers->pluck('id')->all())->delete();
        }

        DB::transaction(function () use ($condominium, $roles) {
            for ($index = 1; $index <= 12; $index++) {
                $role = $roles[($index - 1) % $roles->count()];
                $fullName = 'Operativo Demo ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
                $email = 'operativo.demo26' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '@pastorita.test';

                $user = User::query()->create([
                    'full_name' => $fullName,
                    'document_number' => '88026' . str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                    'email' => $email,
                    'password' => Hash::make('12345678'),
                    'phone' => '300880' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                    'is_active' => true,
                    'is_platform_admin' => false,
                ]);

                Operative::query()->create([
                    'user_id' => $user->id,
                    'condominium_id' => $condominium->id,
                    'position' => $role->name . ' Demo',
                    'contract_type' => $index % 2 === 0 ? 'contratista' : 'planta',
                    'salary' => 1500000 + ($index * 25000),
                    'financial_institution' => 'Bancolombia',
                    'account_type' => $index % 2 === 0 ? 'corriente' : 'ahorros',
                    'account_number' => '7700' . str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                    'eps' => 'EPS Demo ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'arl' => 'ARL Demo ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'contract_start_date' => now()->subDays($index * 2)->toDateString(),
                    'is_active' => $index % 4 !== 0,
                ]);

                UserRole::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'condominium_id' => $condominium->id,
                    ],
                    [
                        'role_id' => $role->id,
                    ]
                );
            }
        });

        $this->command?->info('Seeder OperativesDemoSeeder ejecutado: 12 operativos demo creados.');
    }
}




