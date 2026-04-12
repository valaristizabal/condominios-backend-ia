<?php

use App\Modules\Emergencies\Models\HealthIncident;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('health_incidents', 'reported_by_id')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->foreignId('reported_by_id')
                    ->nullable()
                    ->after('emergency_type_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('health_incidents', 'status')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->string('status')
                    ->default(HealthIncident::STATUS_OPEN)
                    ->after('event_date');
                $table->index('status');
            });
        }

        if (! Schema::hasColumn('health_incidents', 'resolved_at')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->timestamp('resolved_at')
                    ->nullable()
                    ->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('health_incidents', 'reported_by_id')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reported_by_id');
            });
        }

        if (Schema::hasColumn('health_incidents', 'resolved_at')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->dropColumn('resolved_at');
            });
        }

        if (Schema::hasColumn('health_incidents', 'status')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            });
        }
    }
};



