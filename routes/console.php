<?php

use App\Console\Commands\GenerateCleaningRecordsFromSchedulesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cleaning:schedules:generate-records', function () {
    app(GenerateCleaningRecordsFromSchedulesCommand::class)->handle();
})->purpose('Genera registros de limpieza pendientes desde programaciones activas.');

Schedule::call(function () {
    app(GenerateCleaningRecordsFromSchedulesCommand::class)->handle();
})->dailyAt('00:05');

Schedule::command('condominiums:deactivate-expired')->daily();

