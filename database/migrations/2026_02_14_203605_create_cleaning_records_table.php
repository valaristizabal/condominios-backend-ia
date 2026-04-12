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
        Schema::create('cleaning_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->foreignId('cleaning_area_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('operative_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('registered_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('cleaning_date');

            $table->enum('status', ['pending', 'completed'])
                ->default('pending');

            $table->text('observations')->nullable();

            $table->timestamps();

            $table->index('condominium_id');
            $table->index('cleaning_area_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_records');
    }
};

