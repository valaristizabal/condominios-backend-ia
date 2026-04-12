<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                  ->constrained('condominiums')
                  ->cascadeOnDelete();

            $table->foreignId('unit_type_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('tower')->nullable();   // Torre A
            $table->string('number');              // 101, 502, etc
            $table->unsignedSmallInteger('floor')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*Índices estratégicos SaaS*/

            $table->index('condominium_id');
            $table->index('unit_type_id');

            $table->index(['condominium_id', 'number']);
            $table->index(['condominium_id', 'unit_type_id']);

            // Evita que se repita mismo número dentro del mismo condominio
            $table->unique(['condominium_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartments');
    }
};

