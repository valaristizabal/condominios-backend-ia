<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->decimal('administration_fee', 12, 2)->nullable()->after('type');
            $table->date('administration_maturity')->nullable()->after('administration_fee');

            $table->string('property_owner_full_name')->nullable()->after('administration_maturity');
            $table->string('property_owner_document_number', 50)->nullable()->after('property_owner_full_name');
            $table->string('property_owner_email')->nullable()->after('property_owner_document_number');
            $table->string('property_owner_phone', 30)->nullable()->after('property_owner_email');
            $table->date('property_owner_birth_date')->nullable()->after('property_owner_phone');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table) {
            $table->dropColumn([
                'administration_fee',
                'administration_maturity',
                'property_owner_full_name',
                'property_owner_document_number',
                'property_owner_email',
                'property_owner_phone',
                'property_owner_birth_date',
            ]);
        });
    }
};

