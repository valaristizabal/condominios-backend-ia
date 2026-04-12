<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->timestamp('fecha_entrada')->nullable()->after('movement_date');
            $table->timestamp('fecha_salida')->nullable()->after('fecha_entrada');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_entrada',
                'fecha_salida',
            ]);
        });
    }
};

