<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->foreignId('apartment_id')
                ->constrained('apartments')
                ->cascadeOnDelete();

            $table->date('period');
            $table->decimal('amount_total', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2);
            $table->date('due_date');

            $table->enum('status', ['al_dia', 'proximo_a_vencer', 'en_mora', 'pagado'])
                ->default('al_dia');

            $table->text('notes')->nullable();

            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['condominium_id', 'apartment_id', 'period'], 'uq_portfolio_charge_condo_apartment_period');
            $table->index('condominium_id');
            $table->index('apartment_id');
            $table->index('period');
            $table->index('status');
            $table->index(['condominium_id', 'period']);
            $table->index(['condominium_id', 'status']);
            $table->index(['condominium_id', 'due_date']);
            $table->index(['condominium_id', 'balance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_charges');
    }
};
