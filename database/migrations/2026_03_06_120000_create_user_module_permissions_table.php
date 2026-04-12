<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('condominium_id')->constrained('condominiums')->cascadeOnDelete();
            $table->enum('module', [
                'visits',
                'vehicles',
                'vehicle-incidents',
                'employee-entries',
                'correspondences',
                'emergencies',
                'cleaning',
                'inventory',
                'settings',
            ]);
            $table->boolean('can_view')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'module', 'condominium_id'], 'uq_user_module_condominium');
            $table->index(['condominium_id', 'module'], 'idx_condominium_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_permissions');
    }
};

