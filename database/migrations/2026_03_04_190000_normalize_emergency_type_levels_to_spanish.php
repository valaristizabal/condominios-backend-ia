<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('emergency_types') || ! Schema::hasColumn('emergency_types', 'level')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE emergency_types
                MODIFY level ENUM('LOW','MEDIUM','HIGH','CRITICAL','BAJO','MEDIO','ALTO','CRITICO') NOT NULL
            ");
        }

        DB::statement("
            UPDATE emergency_types
            SET level = CASE UPPER(level)
                WHEN 'LOW' THEN 'BAJO'
                WHEN 'MEDIUM' THEN 'MEDIO'
                WHEN 'HIGH' THEN 'ALTO'
                WHEN 'CRITICAL' THEN 'CRITICO'
                WHEN 'BAJO' THEN 'BAJO'
                WHEN 'MEDIO' THEN 'MEDIO'
                WHEN 'ALTO' THEN 'ALTO'
                WHEN 'CRITICO' THEN 'CRITICO'
                ELSE 'MEDIO'
            END
        ");

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE emergency_types
                MODIFY level ENUM('BAJO','MEDIO','ALTO','CRITICO') NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('emergency_types') || ! Schema::hasColumn('emergency_types', 'level')) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE emergency_types
                MODIFY level ENUM('LOW','MEDIUM','HIGH','CRITICAL','BAJO','MEDIO','ALTO','CRITICO') NOT NULL
            ");
        }

        DB::statement("
            UPDATE emergency_types
            SET level = CASE UPPER(level)
                WHEN 'BAJO' THEN 'low'
                WHEN 'MEDIO' THEN 'medium'
                WHEN 'ALTO' THEN 'high'
                WHEN 'CRITICO' THEN 'critical'
                WHEN 'LOW' THEN 'low'
                WHEN 'MEDIUM' THEN 'medium'
                WHEN 'HIGH' THEN 'high'
                WHEN 'CRITICAL' THEN 'critical'
                ELSE 'medium'
            END
        ");

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE emergency_types
                MODIFY level ENUM('low','medium','high','critical') NOT NULL
            ");
        }
    }
};

