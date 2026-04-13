<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('emergency_contacts', 'emergency_type_id')) {
            Schema::table('emergency_contacts', function (Blueprint $table) {
                $table->foreignId('emergency_type_id')
                    ->nullable()
                    ->after('condominium_id')
                    ->constrained('emergency_types')
                    ->nullOnDelete();

                $table->index(['condominium_id', 'emergency_type_id'], 'emergency_contacts_condo_type_idx');
            });
        }

        DB::table('emergency_contacts as contacts')
            ->join('emergency_types as types', function ($join) {
                $join->on('types.condominium_id', '=', 'contacts.condominium_id')
                    ->whereRaw('LOWER(TRIM(types.name)) = LOWER(TRIM(contacts.name))');
            })
            ->whereNull('contacts.emergency_type_id')
            ->update([
                'contacts.emergency_type_id' => DB::raw('types.id'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('emergency_contacts', 'emergency_type_id')) {
            Schema::table('emergency_contacts', function (Blueprint $table) {
                $table->dropIndex('emergency_contacts_condo_type_idx');
                $table->dropConstrainedForeignId('emergency_type_id');
            });
        }
    }
};
