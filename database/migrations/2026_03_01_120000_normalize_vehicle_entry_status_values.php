<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE vehicle_entries MODIFY status VARCHAR(20) NOT NULL");
            DB::statement("
                UPDATE vehicle_entries
                SET status = CASE UPPER(status)
                    WHEN 'ACTIVE' THEN 'INSIDE'
                    WHEN 'INSIDE' THEN 'INSIDE'
                    WHEN 'COMPLETED' THEN 'OUTSIDE'
                    WHEN 'CANCELLED' THEN 'OUTSIDE'
                    WHEN 'OUTSIDE' THEN 'OUTSIDE'
                    ELSE 'OUTSIDE'
                END
            ");
            DB::statement("ALTER TABLE vehicle_entries MODIFY status ENUM('INSIDE', 'OUTSIDE') NOT NULL DEFAULT 'INSIDE'");
        } else {
            DB::table('vehicle_entries')->whereIn('status', ['active', 'ACTIVE'])->update(['status' => 'INSIDE']);
            DB::table('vehicle_entries')->whereIn('status', ['completed', 'cancelled', 'COMPLETED', 'CANCELLED'])->update(['status' => 'OUTSIDE']);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE vehicle_entries MODIFY status VARCHAR(20) NOT NULL");
            DB::statement("
                UPDATE vehicle_entries
                SET status = CASE UPPER(status)
                    WHEN 'INSIDE' THEN 'active'
                    WHEN 'OUTSIDE' THEN 'completed'
                    ELSE 'completed'
                END
            ");
            DB::statement("ALTER TABLE vehicle_entries MODIFY status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active'");
        } else {
            DB::table('vehicle_entries')->where('status', 'INSIDE')->update(['status' => 'active']);
            DB::table('vehicle_entries')->where('status', 'OUTSIDE')->update(['status' => 'completed']);
        }
    }
};

