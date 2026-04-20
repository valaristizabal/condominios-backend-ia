<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('residents', 'administration_due_day')) {
            Schema::table('residents', function (Blueprint $table) {
                if (Schema::hasColumn('residents', 'administration_due_date')) {
                    $table->unsignedTinyInteger('administration_due_day')->nullable()->after('administration_due_date');
                    return;
                }

                if (Schema::hasColumn('residents', 'administration_maturity')) {
                    $table->unsignedTinyInteger('administration_due_day')->nullable()->after('administration_maturity');
                    return;
                }

                $table->unsignedTinyInteger('administration_due_day')->nullable();
            });
        }

        if (Schema::hasColumn('residents', 'administration_due_date')) {
            DB::statement(<<<'SQL'
                UPDATE residents
                SET administration_due_day = DAY(administration_due_date)
                WHERE administration_due_date IS NOT NULL
            SQL);

            Schema::table('residents', function (Blueprint $table) {
                $table->dropColumn('administration_due_date');
            });
        } elseif (Schema::hasColumn('residents', 'administration_maturity')) {
            DB::statement(<<<'SQL'
                UPDATE residents
                SET administration_due_day = DAY(administration_maturity)
                WHERE administration_maturity IS NOT NULL
            SQL);

            Schema::table('residents', function (Blueprint $table) {
                $table->dropColumn('administration_maturity');
            });
        }

        DB::statement(<<<'SQL'
            UPDATE residents
            SET administration_due_day = NULL
            WHERE administration_due_day IS NOT NULL
              AND (administration_due_day < 1 OR administration_due_day > 31)
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('residents', 'administration_due_date')) {
            Schema::table('residents', function (Blueprint $table) {
                $table->date('administration_due_date')->nullable()->after('administration_due_day');
            });
        }

        if (Schema::hasColumn('residents', 'administration_due_day')) {
            DB::statement(<<<'SQL'
                UPDATE residents
                SET administration_due_date = CASE
                    WHEN administration_due_day BETWEEN 1 AND 31 THEN DATE_ADD(
                        DATE_SUB(CURDATE(), INTERVAL DAY(CURDATE()) - 1 DAY),
                        INTERVAL LEAST(administration_due_day, DAY(LAST_DAY(CURDATE()))) - 1 DAY
                    )
                    ELSE NULL
                END
                WHERE administration_due_day IS NOT NULL
            SQL);

            Schema::table('residents', function (Blueprint $table) {
                $table->dropColumn('administration_due_day');
            });
        }
    }
};
