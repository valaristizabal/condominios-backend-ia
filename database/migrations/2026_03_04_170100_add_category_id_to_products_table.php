<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('inventory_id')
                ->constrained('inventory_categories')
                ->nullOnDelete();
            $table->index('category_id');
        });

        $products = DB::table('products')
            ->join('inventories', 'inventories.id', '=', 'products.inventory_id')
            ->whereNotNull('products.category')
            ->select(
                'products.id as product_id',
                'products.category as category_name',
                'inventories.condominium_id as condominium_id'
            )
            ->orderBy('products.id')
            ->get();

        foreach ($products as $row) {
            $name = trim((string) $row->category_name);
            if ($name === '') {
                continue;
            }

            $categoryId = DB::table('inventory_categories')
                ->where('condominium_id', (int) $row->condominium_id)
                ->where('name', $name)
                ->value('id');

            if (! $categoryId) {
                $categoryId = DB::table('inventory_categories')->insertGetId([
                    'condominium_id' => (int) $row->condominium_id,
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('products')
                ->where('id', (int) $row->product_id)
                ->update(['category_id' => $categoryId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
