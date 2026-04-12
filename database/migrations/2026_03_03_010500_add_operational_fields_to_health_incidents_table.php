<?php

use App\Modules\Emergencies\Models\HealthIncident;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('health_incidents', function (Blueprint $table) {
            $table->foreignId('reported_by_id')
                ->nullable()
                ->after('emergency_type_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status')
                ->default(HealthIncident::STATUS_OPEN)
                ->after('event_date');

            $table->timestamp('resolved_at')
                ->nullable()
                ->after('status');

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_by_id');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'resolved_at']);
        });
    }
};


