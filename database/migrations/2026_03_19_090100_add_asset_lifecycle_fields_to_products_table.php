<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('serial')->nullable()->after('asset_code');
            $table->boolean('dado_de_baja')->default(false)->after('is_active');
            $table->foreignId('dado_de_baja_por')
                ->nullable()
                ->after('dado_de_baja')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('fecha_baja')->nullable()->after('dado_de_baja_por');

            $table->index('serial');
            $table->index('dado_de_baja');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dado_de_baja_por');
            $table->dropIndex(['serial']);
            $table->dropIndex(['dado_de_baja']);
            $table->dropColumn([
                'serial',
                'dado_de_baja',
                'fecha_baja',
            ]);
        });
    }
};

