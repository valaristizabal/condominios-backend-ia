<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cleaning_area_checklists')) {
            Schema::table('cleaning_area_checklists', function (Blueprint $table) {
                if (! Schema::hasColumn('cleaning_area_checklists', 'assigned_user_id')) {
                    $table->foreignId('assigned_user_id')->nullable()->after('cleaning_area_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'frequency_type')) {
                    $table->string('frequency_type', 20)->nullable()->after('item_name');
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'repeat_interval')) {
                    $table->unsignedInteger('repeat_interval')->default(1)->after('frequency_type');
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'days_of_week')) {
                    $table->json('days_of_week')->nullable()->after('repeat_interval');
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'start_date')) {
                    $table->date('start_date')->nullable()->after('days_of_week');
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'status')) {
                    $table->string('status', 20)->default('pending')->after('end_date');
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'last_executed_by_id')) {
                    $table->foreignId('last_executed_by_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('cleaning_area_checklists', 'last_executed_at')) {
                    $table->timestamp('last_executed_at')->nullable()->after('last_executed_by_id');
                }
            });

            DB::table('cleaning_area_checklists')->whereNull('frequency_type')->update([
                'frequency_type' => 'weekly',
                'repeat_interval' => 1,
                'start_date' => DB::raw('DATE(created_at)'),
                'status' => 'pending',
            ]);

            $tasks = DB::table('cleaning_area_checklists')->select('id')->get();
            foreach ($tasks as $task) {
                $schedule = DB::table('cleaning_schedules')
                    ->where('description', '[checklist_item:'.$task->id.']')
                    ->first(['frequency_type', 'repeat_interval', 'days_of_week', 'start_date', 'end_date']);

                if (! $schedule) {
                    continue;
                }

                DB::table('cleaning_area_checklists')
                    ->where('id', $task->id)
                    ->update([
                        'frequency_type' => $schedule->frequency_type ?: 'weekly',
                        'repeat_interval' => $schedule->repeat_interval ?: 1,
                        'days_of_week' => $schedule->days_of_week,
                        'start_date' => $schedule->start_date,
                        'end_date' => $schedule->end_date,
                    ]);
            }
        }

        if (Schema::hasTable('cleaning_schedules')) {
            Schema::table('cleaning_schedules', function (Blueprint $table) {
                if (! Schema::hasColumn('cleaning_schedules', 'assigned_user_id')) {
                    $table->foreignId('assigned_user_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('cleaning_checklist_items')) {
            Schema::table('cleaning_checklist_items', function (Blueprint $table) {
                if (! Schema::hasColumn('cleaning_checklist_items', 'source_checklist_item_id')) {
                    $table->foreignId('source_checklist_item_id')
                        ->nullable()
                        ->after('cleaning_record_id')
                        ->constrained('cleaning_area_checklists')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cleaning_checklist_items') && Schema::hasColumn('cleaning_checklist_items', 'source_checklist_item_id')) {
            Schema::table('cleaning_checklist_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('source_checklist_item_id');
            });
        }

        if (Schema::hasTable('cleaning_area_checklists')) {
            Schema::table('cleaning_area_checklists', function (Blueprint $table) {
                if (Schema::hasColumn('cleaning_area_checklists', 'last_executed_at')) {
                    $table->dropColumn('last_executed_at');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'last_executed_by_id')) {
                    $table->dropConstrainedForeignId('last_executed_by_id');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'status')) {
                    $table->dropColumn('status');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'end_date')) {
                    $table->dropColumn('end_date');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'start_date')) {
                    $table->dropColumn('start_date');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'days_of_week')) {
                    $table->dropColumn('days_of_week');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'repeat_interval')) {
                    $table->dropColumn('repeat_interval');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'frequency_type')) {
                    $table->dropColumn('frequency_type');
                }
                if (Schema::hasColumn('cleaning_area_checklists', 'assigned_user_id')) {
                    $table->dropConstrainedForeignId('assigned_user_id');
                }
            });
        }

        if (Schema::hasTable('cleaning_schedules') && Schema::hasColumn('cleaning_schedules', 'assigned_user_id')) {
            Schema::table('cleaning_schedules', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_user_id');
            });
        }
    }
};
