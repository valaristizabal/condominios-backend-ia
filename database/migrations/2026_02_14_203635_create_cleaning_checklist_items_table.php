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
        Schema::create('cleaning_checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cleaning_record_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('item_name'); // Barrido, Trapeado, Desinfección

            $table->boolean('completed')->default(false);

            $table->timestamps();

            $table->index('cleaning_record_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_checklist_items');
    }
};

