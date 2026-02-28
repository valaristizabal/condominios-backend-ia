<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cleaning_areas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->string('name'); // Lobby, Parqueadero, Piscina, etc
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('condominium_id');
            $table->index(['condominium_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_areas');
    }
};
