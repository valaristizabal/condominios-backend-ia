<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_records')) {
            return;
        }

        Schema::table('cleaning_records', function (Blueprint $table) {
            if (! Schema::hasColumn('cleaning_records', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('cleaning_date');
            }

            if (! Schema::hasColumn('cleaning_records', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_records')) {
            return;
        }

        Schema::table('cleaning_records', function (Blueprint $table) {
            if (Schema::hasColumn('cleaning_records', 'finished_at')) {
                $table->dropColumn('finished_at');
            }
        });
    }
};

