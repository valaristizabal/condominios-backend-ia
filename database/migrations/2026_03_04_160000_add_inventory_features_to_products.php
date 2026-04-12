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
        Schema::table('products', function (Blueprint $table) {
            $table->enum('type', ['consumable', 'asset'])
                ->default('consumable')
                ->after('name');
            $table->integer('minimum_stock')
                ->default(0)
                ->after('stock');
            $table->string('asset_code')
                ->nullable()
                ->after('minimum_stock');
            $table->string('location')
                ->nullable()
                ->after('asset_code');

            $table->index('type');
            $table->index('asset_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['asset_code']);
            $table->dropColumn([
                'type',
                'minimum_stock',
                'asset_code',
                'location',
            ]);
        });
    }
};


