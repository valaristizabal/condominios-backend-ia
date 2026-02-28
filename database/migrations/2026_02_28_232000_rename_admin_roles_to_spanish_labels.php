<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', 'administrador_propiedad')
            ->update([
                'name' => 'Administrador Propiedad',
                'description' => 'Administrador del conjunto o propiedad.',
                'updated_at' => now(),
            ]);

        DB::table('roles')
            ->where('name', 'admin_condominio')
            ->update([
                'name' => 'Administrador Propiedad',
                'description' => 'Administrador del conjunto o propiedad.',
                'updated_at' => now(),
            ]);

        DB::table('roles')
            ->where('name', 'super_usuario')
            ->update([
                'name' => 'Super Usuario',
                'description' => 'Super usuario global sin condominio asignado.',
                'updated_at' => now(),
            ]);

        DB::table('roles')
            ->where('name', 'super_admin')
            ->update([
                'name' => 'Super Usuario',
                'description' => 'Super usuario global sin condominio asignado.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'Administrador Propiedad')
            ->update([
                'name' => 'admin_condominio',
                'description' => 'Administrador tenant del condominio.',
                'updated_at' => now(),
            ]);

        DB::table('roles')
            ->where('name', 'Super Usuario')
            ->update([
                'name' => 'super_admin',
                'description' => 'Super administrador global sin condominio asignado.',
                'updated_at' => now(),
            ]);
    }
};
