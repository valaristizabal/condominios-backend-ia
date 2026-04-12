<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Apartment;
use App\\Modules\\Core\\Models\\Condominium;
use App\\Modules\\Core\\Models\\Correspondence;
use App\Modules\Residents\Models\Resident;
use App\Modules\Security\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CorrespondencesDemoSeeder extends Seeder
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
            $this->command?->warn('No hay apartamentos activos para crear correspondencias demo.');
            return;
        }

        $registeredById = User::query()
            ->where('email', 'admin@gmail.com')
            ->value('id');

        Correspondence::query()
            ->where('condominium_id', $condominium->id)
            ->where('courier_company', 'like', 'Demo Courier %')
            ->delete();

        $couriers = [
            'Demo Courier Alpha',
            'Demo Courier Beta',
            'Demo Courier Gamma',
            'Demo Courier Delta',
        ];

        $packageTypes = ['documento', 'paquete'];
        $now = Carbon::now();

        for ($index = 0; $index < 12; $index++) {
            $apartment = $apartments[$index % $apartments->count()];
            $resident = Resident::query()
                ->where('apartment_id', $apartment->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            $createdAt = $now->copy()->subDays(intdiv($index, 2))->subMinutes($index * 9);
            $shouldBeDelivered = $index % 3 !== 0 && $resident !== null;
            $deliveredAt = $shouldBeDelivered ? $createdAt->copy()->addHours(2) : null;

            Correspondence::query()->create([
                'condominium_id' => $condominium->id,
                'apartment_id' => $apartment->id,
                'courier_company' => $couriers[$index % count($couriers)],
                'package_type' => $packageTypes[$index % count($packageTypes)],
                'evidence_photo' => null,
                'digital_signature' => $shouldBeDelivered ? 'correspondence/signatures/demo-signature.png' : null,
                'status' => $shouldBeDelivered
                    ? Correspondence::STATUS_DELIVERED
                    : Correspondence::STATUS_RECEIVED,
                'received_by_id' => $registeredById,
                'resident_receiver_id' => $shouldBeDelivered ? $resident?->id : null,
                'delivered_by_id' => $shouldBeDelivered ? $registeredById : null,
                'delivered_at' => $deliveredAt,
                'created_at' => $createdAt,
                'updated_at' => $deliveredAt ?? $createdAt,
            ]);
        }

        $this->command?->info('Seeder CorrespondencesDemoSeeder ejecutado: 12 correspondencias demo creadas.');
    }
}



