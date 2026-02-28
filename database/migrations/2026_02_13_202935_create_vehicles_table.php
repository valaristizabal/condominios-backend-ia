<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->foreignId('vehicle_type_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('apartment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('plate')->unique();

            $table->enum('owner_type', [
                'resident',
                'visitor',
                'provider'
            ]);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*Indexes*/

            $table->index('condominium_id');
            $table->index('vehicle_type_id');
            $table->index('apartment_id');
            $table->index('owner_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};