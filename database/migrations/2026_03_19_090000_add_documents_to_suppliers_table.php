<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('rut')->nullable()->after('name');
            $table->string('certificacion_bancaria')->nullable()->after('rut');
            $table->string('documento_representante_legal')->nullable()->after('certificacion_bancaria');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'rut',
                'certificacion_bancaria',
                'documento_representante_legal',
            ]);
        });
    }
};

