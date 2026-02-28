<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                  ->constrained('condominiums')
                  ->cascadeOnDelete();

            $table->string('name'); // Apartment, Studio, Commercial, Penthouse

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*Indexes estratégicos */

            $table->index('condominium_id');
            $table->index(['condominium_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_types');
    }
};
