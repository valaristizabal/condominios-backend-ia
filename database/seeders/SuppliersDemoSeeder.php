<?php

namespace Database\Seeders;

use App\Models\Condominium;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SuppliersDemoSeeder extends Seeder
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

        Supplier::query()
            ->where('condominium_id', $condominium->id)
            ->where('name', 'like', 'SUP26-%')
            ->delete();

        for ($index = 1; $index <= 12; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            Supplier::query()->create([
                'condominium_id' => $condominium->id,
                'name' => 'SUP26-' . $suffix,
                'rut' => '900' . str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                'contact_name' => 'Contacto ' . $suffix,
                'phone' => '300' . str_pad((string) (1000000 + $index), 7, '0', STR_PAD_LEFT),
                'email' => 'sup26' . $suffix . '@demo.test',
                'address' => 'Direccion demo ' . $suffix,
                'is_active' => $index % 4 !== 0,
            ]);
        }

        $this->command?->info('Seeder SuppliersDemoSeeder ejecutado: 12 proveedores demo creados.');
    }
}
