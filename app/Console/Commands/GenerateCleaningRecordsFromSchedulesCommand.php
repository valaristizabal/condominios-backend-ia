<?php

namespace App\Console\Commands;

use App\Modules\Cleaning\Models\CleaningChecklistItem;
use App\Modules\Cleaning\Models\CleaningRecord;
use App\Modules\Cleaning\Models\CleaningSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateCleaningRecordsFromSchedulesCommand extends Command
{
    protected $signature = 'cleaning:schedules:generate-records';
    protected $description = 'Genera registros de limpieza pendientes a partir de programaciones activas.';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $createdCount = 0;

        CleaningSchedule::query()
            ->with(['cleaningArea:id,condominium_id,name,is_active'])
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today->toDateString());
            })
            ->orderBy('id')
            ->chunkById(200, function ($schedules) use ($today, &$createdCount) {
                foreach ($schedules as $schedule) {
                    if (! $this->shouldRunOnDate($schedule, $today)) {
                        continue;
                    }

                    if (! $schedule->cleaningArea || ! $schedule->cleaningArea->is_active) {
                        continue;
                    }

                    $marker = $this->scheduleMarker((int) $schedule->id);

                    $alreadyCreated = CleaningRecord::query()
                        ->where('condominium_id', $schedule->condominium_id)
                        ->where('cleaning_area_id', $schedule->cleaning_area_id)
                        ->whereDate('cleaning_date', $today->toDateString())
                        ->where('observations', 'like', '%'.$marker.'%')
                        ->exists();

                    if ($alreadyCreated) {
                        continue;
                    }

                    DB::transaction(function () use ($schedule, $today, $marker, &$createdCount) {
                        $record = CleaningRecord::query()->create([
                            'condominium_id' => $schedule->condominium_id,
                            'cleaning_area_id' => $schedule->cleaning_area_id,
                            'operative_id' => null,
                            'registered_by_id' => null,
                            'cleaning_date' => $today->toDateString(),
                            'status' => 'pending',
                            'observations' => trim(sprintf(
                                'Programado automaticamente: %s %s',
                                $schedule->name,
                                $marker
                            )),
                        ]);

                        $scheduledTaskName = $this->resolveScheduledTaskName($schedule);

                        CleaningChecklistItem::query()->create([
                            'cleaning_record_id' => $record->id,
                            'item_name' => $scheduledTaskName,
                            'completed' => false,
                        ]);

                        $createdCount++;
                    });
                }
            });

        $this->info("Registros programados creados: {$createdCount}");

        return self::SUCCESS;
    }

    private function shouldRunOnDate(CleaningSchedule $schedule, CarbonImmutable $date): bool
    {
        $startDate = CarbonImmutable::parse($schedule->start_date)->startOfDay();
        if ($date->lt($startDate)) {
            return false;
        }

        if ($schedule->end_date) {
            $endDate = CarbonImmutable::parse($schedule->end_date)->endOfDay();
            if ($date->gt($endDate)) {
                return false;
            }
        }

        $interval = max(1, (int) $schedule->repeat_interval);

        return match ($schedule->frequency_type) {
            CleaningSchedule::FREQUENCY_DAILY => $startDate->diffInDays($date) % $interval === 0,
            CleaningSchedule::FREQUENCY_CUSTOM => $startDate->diffInDays($date) % $interval === 0,
            CleaningSchedule::FREQUENCY_WEEKLY => $this->matchesWeeklyRule($schedule, $date, $startDate, $interval),
            CleaningSchedule::FREQUENCY_MONTHLY => $this->matchesMonthlyRule($date, $startDate, $interval),
            default => false,
        };
    }

    private function matchesWeeklyRule(CleaningSchedule $schedule, CarbonImmutable $date, CarbonImmutable $startDate, int $interval): bool
    {
        $days = collect($schedule->days_of_week ?? [])
            ->map(fn ($day) => (int) $day)
            ->unique()
            ->values()
            ->all();

        if (empty($days)) {
            return false;
        }

        $weekday = $date->dayOfWeek;
        if (! in_array($weekday, $days, true)) {
            return false;
        }

        $weeksBetween = intdiv($startDate->diffInDays($date), 7);
        return $weeksBetween % $interval === 0;
    }

    private function matchesMonthlyRule(CarbonImmutable $date, CarbonImmutable $startDate, int $interval): bool
    {
        if ($date->day !== $startDate->day) {
            return false;
        }

        $monthsBetween = $startDate->diffInMonths($date);
        return $monthsBetween % $interval === 0;
    }

    private function scheduleMarker(int $scheduleId): string
    {
        return sprintf('[schedule_id:%d]', $scheduleId);
    }

    private function resolveScheduledTaskName(CleaningSchedule $schedule): string
    {
        $description = (string) ($schedule->description ?? '');
        $pattern = '/\[checklist_item:(\d+)\]/';
        preg_match($pattern, $description, $matches);

        $fallbackName = trim((string) $schedule->name) !== ''
            ? trim((string) $schedule->name)
            : 'Tarea programada';

        if (empty($matches[1])) {
            return $fallbackName;
        }

        $checklistItemId = (int) $matches[1];

        $templateItem = $schedule->cleaningArea
            ?->checklistTemplateItems()
            ->where('id', $checklistItemId)
            ->first();

        return $templateItem?->item_name ?: $fallbackName;
    }
}

