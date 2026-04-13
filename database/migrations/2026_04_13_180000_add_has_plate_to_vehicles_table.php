<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vehicles', 'has_plate')) {
            DB::statement("ALTER TABLE vehicles ADD COLUMN has_plate TINYINT(1) NOT NULL DEFAULT 1 AFTER apartment_id");
        }

        DB::statement("ALTER TABLE vehicles MODIFY plate VARCHAR(20) NULL");
        DB::statement("UPDATE vehicles SET has_plate = CASE WHEN plate IS NULL OR TRIM(plate) = '' THEN 0 ELSE 1 END");
    }

    public function down(): void
    {
        DB::statement("UPDATE vehicles SET plate = CONCAT('SINPLACA-', id) WHERE plate IS NULL OR TRIM(plate) = ''");
        DB::statement("ALTER TABLE vehicles MODIFY plate VARCHAR(20) NOT NULL");

        if (Schema::hasColumn('vehicles', 'has_plate')) {
            DB::statement("ALTER TABLE vehicles DROP COLUMN has_plate");
        }
    }
};
