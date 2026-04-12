<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Apartment;
use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Security\Models\User;
use App\Modules\Vehicles\Models\Vehicle;
use App\Modules\Vehicles\Models\VehicleEntry;
use App\Modules\Vehicles\Models\VehicleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class VehiclesDemoSeeder extends Seeder
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

        $vehicleTypes = VehicleType::query()
            ->where('condominium_id', $condominium->id)
            ->orderBy('id')
            ->get();

        $apartments = Apartment::query()
            ->where('condominium_id', $condominium->id)
            ->orderBy('id')
            ->get();

        if ($vehicleTypes->isEmpty() || $apartments->isEmpty()) {
            $this->command?->warn('Faltan tipos de vehiculo o apartamentos en el condominio activo.');
            return;
        }

        $registeredById = User::query()
            ->where('email', 'admin@gmail.com')
            ->value('id');

        $plates = [];
        for ($i = 1; $i <= 24; $i++) {
            $plates[] = 'DMV26-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        }

        $existingVehicleIds = Vehicle::query()
            ->where('condominium_id', $condominium->id)
            ->whereIn('plate', $plates)
            ->pluck('id');

        if ($existingVehicleIds->isNotEmpty()) {
            VehicleEntry::query()->whereIn('vehicle_id', $existingVehicleIds)->delete();
            Vehicle::query()->whereIn('id', $existingVehicleIds)->delete();
        }

        $owners = ['resident', 'visitor', 'provider'];
        $today = Carbon::today();

        foreach ($plates as $index => $plate) {
            $vehicleType = $vehicleTypes[$index % $vehicleTypes->count()];
            $apartment = $apartments[$index % $apartments->count()];
            $ownerType = $owners[$index % count($owners)];
            $checkInAt = $today->copy()->setTime(6 + (int) floor($index / 2), ($index * 7) % 60);

            $vehicle = Vehicle::query()->create([
                'condominium_id' => $condominium->id,
                'vehicle_type_id' => $vehicleType->id,
                'apartment_id' => $apartment->id,
                'plate' => $plate,
                'owner_type' => $ownerType,
                'is_active' => true,
            ]);

            VehicleEntry::query()->create([
                'condominium_id' => $condominium->id,
                'vehicle_id' => $vehicle->id,
                'registered_by_id' => $registeredById,
                'check_in_at' => $checkInAt,
                'check_out_at' => null,
                'status' => 'INSIDE',
                'observations' => 'Ingreso demo para pruebas de paginacion',
            ]);
        }

        $this->command?->info('Seeder VehiclesDemoSeeder ejecutado: 24 vehiculos y 24 ingresos activos creados.');
    }
}





