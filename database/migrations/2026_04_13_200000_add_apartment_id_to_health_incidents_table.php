<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_incidents', function (Blueprint $table) {
            $table->foreignId('apartment_id')
                ->nullable()
                ->after('emergency_type_id')
                ->constrained('apartments')
                ->nullOnDelete();

            $table->index(['condominium_id', 'apartment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('health_incidents', function (Blueprint $table) {
            $table->dropIndex(['condominium_id', 'apartment_id']);
            $table->dropConstrainedForeignId('apartment_id');
        });
    }
};
