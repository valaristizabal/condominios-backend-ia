<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apartments', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('unit_type_id')
                ->constrained('apartments')
                ->nullOnDelete();

            $table->index(['condominium_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['condominium_id', 'parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
