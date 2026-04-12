<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Condominium;
use App\\Modules\\Core\\Models\\EmployeeEntry;
use App\\Modules\\Core\\Models\\Operative;
use App\Modules\Security\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class EmployeeEntriesDemoSeeder extends Seeder
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

        $operatives = Operative::query()
            ->where('condominium_id', $condominium->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($operatives->isEmpty()) {
            $this->command?->warn('No hay operarios activos para crear ingresos de personal demo.');
            return;
        }

        $registeredById = User::query()
            ->where('email', 'admin@gmail.com')
            ->value('id');

        EmployeeEntry::query()
            ->where('condominium_id', $condominium->id)
            ->where('observations', 'like', 'Ingreso demo personal #% para pruebas de paginacion.%')
            ->delete();

        $today = Carbon::today();

        for ($index = 0; $index < 12; $index++) {
            $operative = $operatives[$index % $operatives->count()];
            $checkInAt = $today->copy()
                ->subDays(intdiv($index, 3))
                ->setTime(6 + ($index % 10), ($index * 7) % 60);

            $isActive = $index === 11;
            $checkOutAt = $isActive ? null : $checkInAt->copy()->addHours(8)->addMinutes(10);

            EmployeeEntry::query()->create([
                'condominium_id' => $condominium->id,
                'operative_id' => $operative->id,
                'registered_by_id' => $registeredById,
                'check_in_at' => $checkInAt,
                'check_out_at' => $checkOutAt,
                'status' => $isActive ? 'active' : 'completed',
                'observations' => 'Ingreso demo personal #' . ($index + 1) . ' para pruebas de paginacion.',
            ]);
        }

        $this->command?->info('Seeder EmployeeEntriesDemoSeeder ejecutado: 12 ingresos de personal creados (11 historial, 1 activo).');
    }
}



