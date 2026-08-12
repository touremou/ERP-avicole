<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
// Vérifie chaque jour à minuit
Schedule::command('farm:release-buildings')->daily();

// Réconciliation nocturne des EFFECTIFS uniquement, par l'outil qui ne fait que
// cela (BatchQuantityService, écriture directe donc sans réveiller l'observer).
//
// C'était `stocks:sync` auparavant, et sa partie « œufs » écrasait chaque nuit le
// niveau de stock des calibres avec la somme de TOUTE la production enregistrée —
// la même colonne que la vente décrémente. Les œufs vendus revenaient donc en
// stock au matin, et l'écart se reconstituait tout seul, indéfiniment. Mesuré :
// 100 alvéoles produites, 30 vendues, 70 restantes ; après passage, 100.
//
// `stocks:sync` existe toujours, en lecture seule sans --force, et se lance à la
// main : une réécriture de quantités de magasin ne s'applique pas sans que personne
// n'ait lu le résultat.
//
// --force EXPLICITE ici : la convention de ces commandes est « simulation par
// défaut » (#220). L'omettre transformerait cette réconciliation nocturne en
// simulation muette — la panne silencieuse par excellence. Et c'est bien celle-ci
// qui doit ÉCRIRE : elle dérive d'un registre complet (les pointages), elle est
// idempotente, et elle écrit en direct sans réveiller l'observer.
Schedule::command('batches:rebuild-quantities --force')->daily();

Schedule::command('tasks:generate')->dailyAt('05:00');

// Verrou anti-doublon : libère les prises de tâche abandonnées (timeout).
Schedule::command('tasks:release-stale')->everyFifteenMinutes();

// Résumé quotidien WhatsApp — heure pilotée par le paramètre whatsapp.daily_summary_hour.
//
// L'heure passe par Setting::hour() et NON par setting() : la valeur vient d'un
// champ de formulaire libre, et une heure impossible (« 25:00 ») faisait lever le
// constructeur d'expression cron. `schedule:run` échouait alors AVANT d'exécuter
// quoi que ce soit, arrêtant les VINGT-TROIS tâches de ce fichier — sauvegardes
// comprises — à chaque minute et en silence, la ligne de cron recommandée
// redirigeant sa sortie vers /dev/null. Vérifié à la main.
Schedule::command('avismart:daily-summary')->dailyAt(Setting::hour('whatsapp.daily_summary_hour', '07:00'));

// Réessaie les notifications WhatsApp en échec (coupure réseau, panne API...)
Schedule::command('avismart:retry-failed-notifications')->everyFifteenMinutes();

// Digest d'activité par employé (fin de journée) — redevabilité hors site
Schedule::command('avismart:activity-digest')->dailyAt(Setting::hour('whatsapp.activity_digest_hour', '20:00'));

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

// Complétude des registres HACCP (spec Transformation §9) : relevés de
// température < N/jour et abattages du jour sans CCP 3 → alerte en fin
// de journée, quand il est encore temps de compléter.
Schedule::command('haccp:check-registers')->dailyAt('18:00');

// Péremption des consommables (vaccins, médicaments, intrants…) : alerte WhatsApp
Schedule::command('stock:check-expiry')->dailyAt('06:15');

// Contrats à terme (CDD / Journaliers) : rappel avant l'échéance, et alerte
// critique dès que le terme est dépassé sans décision — le promoteur vit à
// l'étranger, l'alerte doit venir à lui.
Schedule::command('hr:check-contracts')->dailyAt('06:20');

// Pointage manquant — LE SOIR, quand la journée se rattrape encore. Découverte
// à la paie, l'information n'a plus de valeur : on ne reconstitue pas un mois de
// présence de mémoire. Sans feuille, la paie présume les jours travaillés et les
// règle en entier.
Schedule::command('hr:check-attendance')->dailyAt('18:30');

// Purge du journal d'audit au-delà de la rétention (config/activitylog.php,
// défaut 365 j) — borne la croissance de la table activity_log.
Schedule::command('activitylog:clean')->weekly();

// Sauvegarde automatisée (base + fichiers utilisateurs) : nettoyage de la
// rétention puis sauvegarde quotidienne aux heures creuses.
Schedule::command('backup:clean')->dailyAt('01:30');
Schedule::command('backup:run')->dailyAt('02:00');

// SANTÉ des sauvegardes, APRÈS celle de la nuit. Deux runbooks désignaient
// `backup:monitor` comme ce contrôle — il n'était planifié nulle part, et même lancé
// n'aurait prévenu personne : toutes les notifications de la bibliothèque sont
// désactivées dans config/backup.php. Une sauvegarde qui échouait à 02:00 n'était
// donc annoncée à personne. Celle-ci alerte les administrateurs par la chaîne de
// l'application, et reste muette quand tout va bien.
Schedule::command('avismart:check-backups')->dailyAt('03:00');

// Relances de paiement : rappel aux clients en retard (anti-doublon intégré).
Schedule::command('sales:payment-reminders')->dailyAt('09:00');

// Licence : vérification en ligne (révocation / renouvellement à distance).
// Sans LICENSE_SERVER_URL, la commande ne fait rien (mode hors-ligne).
Schedule::command('license:sync')->dailyAt('04:00');

// Télémétrie IoT : association des relevés en tampon au lot actif du
// bâtiment (lieu + heure), puis rétention bornée (90 j).
Schedule::command('telemetry:process')->everyFiveMinutes();
Schedule::command('telemetry:prune')->dailyAt('03:30');