<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condominiums', function (Blueprint $table) {
            $table->id();

            $table->string('name')->index();
            $table->string('tenant_code', 50)->unique();
            $table->string('type')->nullable()->index();

            $table->text('common_areas')->nullable();
            $table->string('tower')->nullable();
            $table->unsignedSmallInteger('floors')->nullable();

            $table->string('address')->nullable();
            $table->string('contact_info')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condominiums');
    }
};

