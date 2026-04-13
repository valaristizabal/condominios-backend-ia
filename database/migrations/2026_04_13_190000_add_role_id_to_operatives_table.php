<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operatives', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->after('user_id')
                ->constrained('roles')
                ->nullOnDelete();

            $table->index(['condominium_id', 'role_id']);
        });

        DB::statement('
            UPDATE operatives o
            INNER JOIN user_role ur
                ON ur.user_id = o.user_id
               AND ur.condominium_id = o.condominium_id
            SET o.role_id = ur.role_id
            WHERE o.role_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('operatives', function (Blueprint $table) {
            $table->dropIndex(['condominium_id', 'role_id']);
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
