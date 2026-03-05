<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasUnitCost = Schema::hasColumn('products', 'unit_cost');
        $hasTotalValue = Schema::hasColumn('products', 'total_value');

        Schema::table('products', function (Blueprint $table) use ($hasUnitCost, $hasTotalValue) {
            if (! $hasUnitCost) {
                $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_measure');
            }

            if (! $hasTotalValue) {
                $table->decimal('total_value', 14, 2)->nullable()->after('unit_cost');
            }
        });

        if ($hasUnitCost) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE products MODIFY unit_cost DECIMAL(12,2) NULL');
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE products ALTER COLUMN unit_cost TYPE NUMERIC(12,2)');
            }
        }

        if (Schema::hasColumn('products', 'total_value') && Schema::hasColumn('products', 'stock') && Schema::hasColumn('products', 'unit_cost')) {
            DB::statement('UPDATE products SET total_value = CASE WHEN unit_cost IS NULL THEN NULL ELSE ROUND(stock * unit_cost, 2) END');
        }
    }

    public function down(): void
    {
        $hasTotalValue = Schema::hasColumn('products', 'total_value');

        Schema::table('products', function (Blueprint $table) use ($hasTotalValue) {
            if ($hasTotalValue) {
                $table->dropColumn('total_value');
            }
        });
    }
};
