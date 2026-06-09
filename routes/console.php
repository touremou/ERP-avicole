<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
// Vérifie chaque jour à minuit
Schedule::command('farm:release-buildings')->daily();

// Exécute la synchronisation chaque soir à minuit
Schedule::command('stocks:sync')->daily();

Schedule::command('tasks:generate')->dailyAt('05:00');