<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operatives', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('condominium_id')
                  ->constrained('condominiums')
                  ->cascadeOnDelete();

            $table->string('position'); // guard, cleaning, admin, etc

            $table->enum('contract_type', ['contratista', 'planta']);
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('financial_institution')->nullable();
            $table->enum('account_type', ['ahorros', 'corriente'])->nullable();
            $table->string('account_number')->nullable();
            $table->date('contract_start_date')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index('user_id');
            $table->index('condominium_id');
            $table->index(['condominium_id', 'contract_type']);
            $table->unique(['user_id', 'condominium_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operatives');
    }
};

