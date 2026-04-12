<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropUnique('vehicles_plate_unique');
            });
        } catch (\Throwable $exception) {
            // En algunos entornos el nombre del indice puede variar.
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->unique(['condominium_id', 'plate'], 'vehicles_condominium_plate_unique');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropUnique('vehicles_condominium_plate_unique');
            });
        } catch (\Throwable $exception) {
            // Si no existe el indice, no hay rollback de ese paso.
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->unique('plate', 'vehicles_plate_unique');
        });
    }
};

