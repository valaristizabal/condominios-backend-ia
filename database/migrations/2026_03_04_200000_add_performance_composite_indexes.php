<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_records', function (Blueprint $table) {
            $table->index(['condominium_id', 'cleaning_date', 'status'], 'idx_cleaning_records_condo_date_status');
        });

        Schema::table('employee_entries', function (Blueprint $table) {
            $table->index(
                ['condominium_id', 'status', 'check_in_at', 'check_out_at'],
                'idx_employee_entries_condo_status_checkin_checkout'
            );
        });

        Schema::table('vehicle_entries', function (Blueprint $table) {
            $table->index(
                ['condominium_id', 'status', 'check_out_at', 'check_in_at'],
                'idx_vehicle_entries_condo_status_checkout_checkin'
            );
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(
                ['product_id', 'movement_date', 'id'],
                'idx_inventory_movements_product_date_id'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_records', function (Blueprint $table) {
            $table->dropIndex('idx_cleaning_records_condo_date_status');
        });

        Schema::table('employee_entries', function (Blueprint $table) {
            $table->dropIndex('idx_employee_entries_condo_status_checkin_checkout');
        });

        Schema::table('vehicle_entries', function (Blueprint $table) {
            $table->dropIndex('idx_vehicle_entries_condo_status_checkout_checkin');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('idx_inventory_movements_product_date_id');
        });
    }
};

