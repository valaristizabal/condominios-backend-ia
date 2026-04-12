<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apartments', function (Blueprint $table) {
            $table->dropUnique('apartments_condominium_id_number_unique');
            $table->unique(['condominium_id', 'tower', 'number'], 'apartments_condominium_tower_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table) {
            $table->dropUnique('apartments_condominium_tower_number_unique');
            $table->unique(['condominium_id', 'number'], 'apartments_condominium_id_number_unique');
        });
    }
};
