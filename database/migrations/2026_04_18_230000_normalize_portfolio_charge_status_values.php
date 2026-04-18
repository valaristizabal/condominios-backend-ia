<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_charges')) {
            return;
        }

        DB::table('portfolio_charges')
            ->where('status', 'proximo_vencer')
            ->update(['status' => 'proximo_a_vencer']);

        DB::table('portfolio_charges')
            ->where('status', 'pagada')
            ->update(['status' => 'pagado']);

        DB::statement(
            "ALTER TABLE portfolio_charges MODIFY status ENUM('al_dia','proximo_a_vencer','en_mora','pagado') NOT NULL DEFAULT 'al_dia'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('portfolio_charges')) {
            return;
        }

        DB::table('portfolio_charges')
            ->where('status', 'proximo_a_vencer')
            ->update(['status' => 'proximo_vencer']);

        DB::table('portfolio_charges')
            ->where('status', 'pagado')
            ->update(['status' => 'pagada']);

        DB::statement(
            "ALTER TABLE portfolio_charges MODIFY status ENUM('al_dia','proximo_vencer','en_mora','pagada') NOT NULL DEFAULT 'al_dia'"
        );
    }
};

