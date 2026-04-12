<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operatives', function (Blueprint $table) {
            if (! Schema::hasColumn('operatives', 'eps')) {
                $table->string('eps', 120)->nullable()->after('account_number');
            }

            if (! Schema::hasColumn('operatives', 'arl')) {
                $table->string('arl', 120)->nullable()->after('eps');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operatives', function (Blueprint $table) {
            if (Schema::hasColumn('operatives', 'arl')) {
                $table->dropColumn('arl');
            }

            if (Schema::hasColumn('operatives', 'eps')) {
                $table->dropColumn('eps');
            }
        });
    }
};


