<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_records', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('cleaning_date');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->index('started_at');
            $table->index('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_records', function (Blueprint $table) {
            $table->dropIndex(['started_at']);
            $table->dropIndex(['finished_at']);
            $table->dropColumn(['started_at', 'finished_at']);
        });
    }
};

