<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_contacts', function (Blueprint $table) {

            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('phone_number', 30);
            $table->string('icon', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('condominium_id');
            $table->index(['condominium_id', 'is_active']);
            $table->unique(['condominium_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
    }
};

