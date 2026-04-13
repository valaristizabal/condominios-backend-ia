<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_incidents', function (Blueprint $table) {
            $table->json('evidence_paths')->nullable()->after('evidence_path');
        });

        DB::table('vehicle_incidents')
            ->whereNotNull('evidence_path')
            ->update([
                'evidence_paths' => DB::raw('JSON_ARRAY(evidence_path)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('vehicle_incidents', function (Blueprint $table) {
            $table->dropColumn('evidence_paths');
        });
    }
};
