<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->foreignId('vehicle_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('registered_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();

            $table->enum('status', [
                'active',
                'completed',
                'cancelled'
            ])->default('active');

            $table->text('observations')->nullable();

            $table->timestamps();

            /*Indexes*/

            $table->index('condominium_id');
            $table->index('vehicle_id');
            $table->index('status');
            $table->index(['condominium_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_entries');
    }
};
