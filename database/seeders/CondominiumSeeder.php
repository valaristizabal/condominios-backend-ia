<?php

namespace Database\Seeders;

use App\\Modules\\Core\\Models\\Condominium;
use Illuminate\Database\Seeder;

class CondominiumSeeder extends Seeder
{
    public function run(): void
    {
        Condominium::updateOrCreate(
            ['tenant_code' => 'la-pastorita'],
            [
                'name' => 'La pastorita',
                'type' => 'residencial',
                'is_active' => true,
            ]
        );
    }
}


