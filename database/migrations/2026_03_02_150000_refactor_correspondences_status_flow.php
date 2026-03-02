<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondences', function (Blueprint $table) {
            $table->dropIndex('correspondences_delivered_index');
            $table->dropColumn('delivered');

            $table->string('status')->default('RECEIVED_BY_SECURITY')->after('digital_signature');
            $table->foreignId('resident_receiver_id')
                ->nullable()
                ->after('received_by_id')
                ->constrained('residents')
                ->nullOnDelete();
            $table->timestamp('delivered_at')->nullable()->after('delivered_by_id');

            $table->index('status');
            $table->index('resident_receiver_id');
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('correspondences', function (Blueprint $table) {
            $table->dropForeign(['resident_receiver_id']);
            $table->dropIndex('correspondences_status_index');
            $table->dropIndex('correspondences_resident_receiver_id_index');
            $table->dropIndex('correspondences_delivered_at_index');

            $table->dropColumn(['status', 'resident_receiver_id', 'delivered_at']);

            $table->boolean('delivered')->default(false)->after('evidence_photo');
            $table->index('delivered');
        });
    }
};
