<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyRole = DB::table('roles')->where('name', 'super administrador')->first();

        $adminCondominioRoleId = DB::table('roles')->where('name', 'admin_condominio')->value('id');

        if (! $adminCondominioRoleId) {
            $adminCondominioRoleId = DB::table('roles')->insertGetId([
                'name' => 'admin_condominio',
                'description' => 'Administrador tenant del condominio.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($legacyRole) {
            DB::table('user_role')
                ->where('role_id', $legacyRole->id)
                ->update(['role_id' => $adminCondominioRoleId]);

            DB::table('roles')->where('id', $legacyRole->id)->delete();
        }
    }

    public function down(): void
    {
        $adminCondominioRole = DB::table('roles')->where('name', 'admin_condominio')->first();

        $legacyRoleId = DB::table('roles')->where('name', 'super administrador')->value('id');
        if (! $legacyRoleId) {
            $legacyRoleId = DB::table('roles')->insertGetId([
                'name' => 'super administrador',
                'description' => 'Rol con acceso total al sistema.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($adminCondominioRole) {
            DB::table('user_role')
                ->where('role_id', $adminCondominioRole->id)
                ->update(['role_id' => $legacyRoleId]);

            DB::table('roles')->where('id', $adminCondominioRole->id)->delete();
        }
    }
};


