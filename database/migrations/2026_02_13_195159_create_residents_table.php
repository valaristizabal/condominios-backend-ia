<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('apartment_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->enum('type', ['propietario', 'arrendatario']);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /* Indexes*/

            $table->index('user_id');
            $table->index('apartment_id');
            $table->index(['apartment_id', 'type']);

            // Un usuario no puede estar duplicado en el mismo apartamento
            $table->unique(['user_id', 'apartment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
