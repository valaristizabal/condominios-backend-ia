<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('condominiums', 'expiration_date')) {
            Schema::table('condominiums', function (Blueprint $table) {
                $table->date('expiration_date')->nullable()->after('is_active');
                $table->index('expiration_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('condominiums', 'expiration_date')) {
            Schema::table('condominiums', function (Blueprint $table) {
                $table->dropIndex(['expiration_date']);
                $table->dropColumn('expiration_date');
            });
        }
    }
};

