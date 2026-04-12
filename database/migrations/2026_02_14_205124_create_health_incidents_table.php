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
        Schema::create('health_incidents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->foreignId('emergency_type_id')
                ->constrained('emergency_types')
                ->cascadeOnDelete();

            $table->string('event_type');
            $table->string('event_location')->nullable();

            $table->text('description')->nullable();

            $table->dateTime('event_date');

            $table->timestamps();

            $table->index('condominium_id');
            $table->index('emergency_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_incidents');
    }
};

