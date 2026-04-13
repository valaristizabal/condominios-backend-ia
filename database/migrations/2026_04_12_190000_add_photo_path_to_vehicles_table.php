<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vehicles', 'photo_path')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->string('photo_path')->nullable()->after('owner_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vehicles', 'photo_path')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('photo_path');
            });
        }
    }
};
