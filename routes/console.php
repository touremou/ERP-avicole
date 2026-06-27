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

// Digest d'activité par employé (fin de journée) — redevabilité hors site
Schedule::command('avismart:activity-digest')->dailyAt(setting('whatsapp.activity_digest_hour', '20:00'));

// Rappels du calendrier cultural (récoltes à venir / en retard) — module Cultures
Schedule::command('cultures:harvest-reminders')->dailyAt('06:30');

// Récupération automatique de la météo (Open-Meteo) → relevés agronomiques.
// Avant les alertes agronomiques (06:45) qui consomment ces relevés.
Schedule::command('weather:fetch')->dailyAt('05:15');

// Alertes agronomiques (risques semis/récolte, météo) — module Cultures
Schedule::command('cultures:agronomic-alerts')->dailyAt('06:45');

// Dosage aliment recommandé par bâtiment (BatchAdvisorService)
Schedule::command('avismart:feeding-dosage')->dailyAt('06:00');

// CMMS : génère les tâches maintenance préventive pour les actifs dus dans ≤ 48h
Schedule::command('maintenance:check')->dailyAt('05:30');

// Péremption des consommables (vaccins, médicaments, intrants…) : alerte WhatsApp
Schedule::command('stock:check-expiry')->dailyAt('06:15');

// Purge du journal d'audit au-delà de la rétention (config/activitylog.php,
// défaut 365 j) — borne la croissance de la table activity_log.
Schedule::command('activitylog:clean')->weekly();