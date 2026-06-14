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

// Résumé quotidien WhatsApp — heure pilotée par le paramètre whatsapp.daily_summary_hour
Schedule::command('avismart:daily-summary')->dailyAt(setting('whatsapp.daily_summary_hour', '07:00'));

// Réessaie les notifications WhatsApp en échec (coupure réseau, panne API...)
Schedule::command('avismart:retry-failed-notifications')->everyFifteenMinutes();