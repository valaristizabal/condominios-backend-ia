<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_incidents', function (Blueprint $table) {
            $table->id();

            /*Relaciones principales*/

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            // Puede existir incidente aunque el vehículo no esté registrado
            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('apartment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('registered_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*Información del incidente*/

            $table->string('plate')->nullable();

            $table->enum('incident_type', [
                'bad_parking',
                'unauthorized',
                'damage',
                'suspicious',
                'other'
            ]);

            $table->text('observations')->nullable();

            $table->string('evidence_path')->nullable();

            $table->boolean('resolved')->default(false);

            $table->timestamps();

            /*Índices estratégicos*/

            $table->index('condominium_id');
            $table->index('vehicle_id');
            $table->index('apartment_id');
            $table->index('incident_type');
            $table->index(['condominium_id', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_incidents');
    }
};

