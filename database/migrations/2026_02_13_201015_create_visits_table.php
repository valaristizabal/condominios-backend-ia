<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                  ->constrained('condominiums')
                  ->cascadeOnDelete();

            $table->foreignId('apartment_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('registered_by_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->string('full_name');
            $table->string('document_number')->nullable();
            $table->string('phone')->nullable();

            $table->string('destination')->nullable(); // opcional

            $table->boolean('background_check')->default(false);
            $table->text('carried_items')->nullable();
            $table->string('photo')->nullable();

            $table->enum('status', ['active', 'completed', 'cancelled'])
                  ->default('active');

            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();

            $table->timestamps();

            /*Indexes */

            $table->index('condominium_id');
            $table->index('apartment_id');
            $table->index('status');
            $table->index(['condominium_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
