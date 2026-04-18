<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_collections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->foreignId('charge_id')
                ->constrained('portfolio_charges')
                ->cascadeOnDelete();

            $table->foreignId('apartment_id')
                ->constrained('apartments')
                ->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('condominium_id');
            $table->index('charge_id');
            $table->index('apartment_id');
            $table->index('payment_date');
            $table->index(['condominium_id', 'payment_date']);
            $table->index(['condominium_id', 'apartment_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_collections');
    }
};

