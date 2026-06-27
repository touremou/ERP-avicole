<?php

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Journalisation d'audit (qui a modifié quoi, quand) — s'appuie sur le standard
 * spatie/laravel-activitylog. Appliqué aux modèles « de décision » sensibles
 * (transactions financières, accès) plutôt qu'aux compteurs opérationnels à
 * forte fréquence (quantités de stock, effectifs) déjà tracés ailleurs
 * (StockMovement, DailyCheck) — pour un journal lisible et anti-fraude.
 *
 * - logOnlyDirty : ne journalise que les attributs réellement modifiés.
 * - dontSubmitEmptyLogs : pas d'entrée si rien n'a changé.
 * - le « causer » (auteur) est renseigné automatiquement avec l'utilisateur
 *   authentifié ; null pour les actions système (cron, seeders).
 */
trait AuditsChanges
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('audit');
    }
}
