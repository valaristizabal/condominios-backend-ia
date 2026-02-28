<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->string('name');

            $table->enum('level', ['low', 'medium', 'critical']);

            //  Agregado correctamente
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('condominium_id');
            $table->index(['condominium_id', 'level']);
            $table->index(['condominium_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_types');
    }
};
