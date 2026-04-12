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
        Schema::create('cleaning_area_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaning_area_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('item_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_area_checklists');
    }
};

