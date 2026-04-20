<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_records', function (Blueprint $table) {
            if (! Schema::hasColumn('cleaning_records', 'evidence_paths')) {
                $table->json('evidence_paths')->nullable()->after('observations');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_records', function (Blueprint $table) {
            if (Schema::hasColumn('cleaning_records', 'evidence_paths')) {
                $table->dropColumn('evidence_paths');
            }
        });
    }
};
