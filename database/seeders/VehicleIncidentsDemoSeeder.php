<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Apartment;
use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Security\Models\User;
use App\Modules\Vehicles\Models\Vehicle;
use App\Modules\Vehicles\Models\VehicleIncident;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class VehicleIncidentsDemoSeeder extends Seeder
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
            ->orderBy('id')
            ->get();

        $vehicles = Vehicle::query()
            ->where('condominium_id', $condominium->id)
            ->orderBy('id')
            ->get();

        if ($apartments->isEmpty()) {
            $this->command?->warn('No hay apartamentos en el condominio activo para registrar novedades.');
            return;
        }

        $registeredById = User::query()
            ->where('email', 'admin@gmail.com')
            ->value('id');

        $plates = [];
        for ($i = 1; $i <= 12; $i++) {
            $plates[] = 'NVI26-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        }

        VehicleIncident::query()
            ->where('condominium_id', $condominium->id)
            ->where(function ($query) use ($plates) {
                $query->whereIn('plate', $plates)
                    ->orWhere('observations', 'like', 'Novedad demo #% para pruebas de paginación.%');
            })
            ->delete();

        $types = ['unauthorized', 'damage', 'other', 'bad_parking', 'suspicious'];
        $today = Carbon::today();

        foreach ($plates as $index => $plate) {
            $apartment = $apartments[$index % $apartments->count()];
            $vehicle = $vehicles->isNotEmpty() ? $vehicles[$index % $vehicles->count()] : null;
            $createdAt = $today->copy()->subDays(intdiv($index, 2))->setTime(7 + ($index % 10), ($index * 5) % 60);
            $isResolved = $index % 3 === 0;

            VehicleIncident::query()->create([
                'condominium_id' => $condominium->id,
                'vehicle_id' => $vehicle?->id,
                'apartment_id' => $apartment->id,
                'registered_by_id' => $registeredById,
                'plate' => $plate,
                'incident_type' => $types[$index % count($types)],
                'observations' => 'Novedad demo #' . ($index + 1) . ' para pruebas de paginación.',
                'evidence_path' => null,
                'resolved' => $isResolved,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $this->command?->info('Seeder VehicleIncidentsDemoSeeder ejecutado: 12 novedades vehiculares creadas.');
    }
}




