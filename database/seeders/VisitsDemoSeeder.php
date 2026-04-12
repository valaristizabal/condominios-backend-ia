<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Apartment;
use App\\Modules\\Core\\Models\\Condominium;
use App\Modules\Security\Models\User;
use App\Modules\Visits\Models\Visit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class VisitsDemoSeeder extends Seeder
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

        $registeredById = User::query()
            ->where('email', 'admin@gmail.com')
            ->value('id');

        $apartmentMap = Apartment::query()
            ->where('condominium_id', $condominium->id)
            ->get()
            ->keyBy('number');

        $today = Carbon::today();

        $rows = [
            ['full_name' => 'Mario Alberto Pineda', 'document_number' => 'VST-900001', 'phone' => '3105001001', 'apartment_number' => 'A101', 'carried_items' => 'Caja de repuestos para lavadora', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(7, 25), 'check_out_at' => $today->copy()->setTime(8, 2)],
            ['full_name' => 'Camila Torres', 'document_number' => 'VST-900002', 'phone' => '3105001002', 'apartment_number' => 'A102', 'carried_items' => 'Paquete de documentos', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(8, 10), 'check_out_at' => $today->copy()->setTime(8, 44)],
            ['full_name' => 'Luis Hernandez', 'document_number' => 'VST-900003', 'phone' => '3105001003', 'apartment_number' => 'A201', 'carried_items' => 'Maletin de herramientas', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(9, 5), 'check_out_at' => null],
            ['full_name' => 'Paula Cardenas', 'document_number' => 'VST-900004', 'phone' => '3105001004', 'apartment_number' => 'A202', 'carried_items' => 'Bolsa de mercado', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(9, 32), 'check_out_at' => $today->copy()->setTime(10, 7)],
            ['full_name' => 'Andres Felipe Lozano', 'document_number' => 'VST-900005', 'phone' => '3105001005', 'apartment_number' => 'B101', 'carried_items' => 'Portatil y carpeta', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(10, 15), 'check_out_at' => null],
            ['full_name' => 'Yolanda Perez', 'document_number' => 'VST-900006', 'phone' => '3105001006', 'apartment_number' => 'B102', 'carried_items' => 'Medicamentos', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(11, 0), 'check_out_at' => $today->copy()->setTime(11, 21)],
            ['full_name' => 'Sebastian Castro', 'document_number' => 'VST-900007', 'phone' => '3105001007', 'apartment_number' => 'B201', 'carried_items' => 'Caja de delivery', 'background_check' => false, 'check_in_at' => $today->copy()->setTime(11, 47), 'check_out_at' => $today->copy()->setTime(12, 3)],
            ['full_name' => 'Viviana Acosta', 'document_number' => 'VST-900008', 'phone' => '3105001008', 'apartment_number' => 'B202', 'carried_items' => 'Ninguno', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(13, 25), 'check_out_at' => null],
            ['full_name' => 'Ricardo Santamaria', 'document_number' => 'VST-900009', 'phone' => '3105001009', 'apartment_number' => 'N301', 'carried_items' => 'Kit de mantenimiento de internet', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(14, 12), 'check_out_at' => $today->copy()->setTime(15, 5)],
            ['full_name' => 'Daniela Mejia', 'document_number' => 'VST-900010', 'phone' => '3105001010', 'apartment_number' => 'N302', 'carried_items' => 'Regalo y bolsa de ropa', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(16, 40), 'check_out_at' => null],
            ['full_name' => 'Jairo Monroy', 'document_number' => 'VST-900011', 'phone' => '3105001011', 'apartment_number' => 'A101', 'carried_items' => 'Herramientas de plomeria', 'background_check' => true, 'check_in_at' => $today->copy()->setTime(18, 5), 'check_out_at' => null],
        ];

        $documentNumbers = array_map(static fn (array $row) => $row['document_number'], $rows);

        Visit::query()
            ->where('condominium_id', $condominium->id)
            ->whereIn('document_number', $documentNumbers)
            ->delete();

        foreach ($rows as $row) {
            $apartment = $apartmentMap->get($row['apartment_number']);
            if (! $apartment) {
                continue;
            }

            $checkOutAt = $row['check_out_at'];
            $status = $checkOutAt ? 'OUTSIDE' : 'INSIDE';

            Visit::query()->create([
                'condominium_id' => $condominium->id,
                'apartment_id' => $apartment->id,
                'registered_by_id' => $registeredById,
                'full_name' => $row['full_name'],
                'document_number' => $row['document_number'],
                'phone' => $row['phone'],
                'destination' => 'Inmueble ' . $apartment->number,
                'background_check' => (bool) $row['background_check'],
                'carried_items' => $row['carried_items'],
                'photo' => null,
                'status' => $status,
                'check_in_at' => $row['check_in_at'],
                'check_out_at' => $checkOutAt,
            ]);
        }

        $this->command?->info('Seeder VisitsDemoSeeder ejecutado: 11 registros de visitantes creados/actualizados.');
    }
}



