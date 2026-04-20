<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominium_id')
                ->constrained('condominiums')
                ->cascadeOnDelete();

            $table->date('registered_at');
            $table->string('expense_type');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method');
            $table->text('observations')->nullable();
            $table->string('support_path')->nullable();
            $table->string('registered_by');
            $table->string('status');

            $table->timestamps();

            $table->index('condominium_id');
            $table->index('registered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
