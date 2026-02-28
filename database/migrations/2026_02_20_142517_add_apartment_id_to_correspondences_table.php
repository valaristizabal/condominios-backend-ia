<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('correspondences', function (Blueprint $table) {

            $table->foreignId('apartment_id')
                  ->nullable()
                  ->after('condominium_id')
                  ->constrained()
                  ->cascadeOnDelete();

        });
    }

    public function down()
    {
        Schema::table('correspondences', function (Blueprint $table) {
            $table->dropForeign(['apartment_id']);
            $table->dropColumn('apartment_id');
        });
    }
};