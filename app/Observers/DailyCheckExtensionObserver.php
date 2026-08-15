<?php

namespace App\Observers;

use App\Models\DailyCheckExtension;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\Log;

/**
 * ALERTE QUAND L'EAU DEVIENT CRITIQUE.
 *
 * `getWaterAlerts()` calculait déjà tout — trois niveaux, seuils réglables, messages
 * clairs — mais n'était consommée que par TROIS ÉCRANS : le tableau de bord, un
 * rapport, et la fiche du lot. Aucune notification n'en partait.
 *
 * Une chute d'oxygène tue un bassin en quelques heures. L'alerte n'atteignait que la
 * personne qui ouvrait la page : ni le promoteur, à l'étranger, ni même le technicien
 * qui venait de saisir le relevé.
 *
 * ─── POURQUOI UN OBSERVATEUR, ET NON LE CONTRÔLEUR ───
 *
 * L'extension est écrite à DEUX endroits (création et modification d'un pointage).
 * Brancher l'alerte dans le contrôleur aurait dupliqué la règle — la forme de défaut
 * que cet audit corrige depuis le début — et laissé de côté tout chemin futur.
 *
 * ─── CE QUI NE DÉCLENCHE PAS ───
 *
 *   • les niveaux « warning » : un avertissement à chaque dérive de pH de 0,2
 *     apprendrait à ignorer le canal, et c'est l'asphyxie qu'on veut voir passer ;
 *   • une modification qui ne touche AUCUNE mesure d'eau : corriger la production
 *     laitière du même pointage ne doit pas ré-alerter sur une eau inchangée.
 */
class DailyCheckExtensionObserver
{
    /** Colonnes dont la modification peut changer le verdict sur l'eau. */
    private const WATER_COLUMNS = ['water_temp', 'water_ph', 'water_o2_ppm', 'water_ammonia_ppm'];

    public function created(DailyCheckExtension $extension): void
    {
        $this->alertIfCritical($extension);
    }

    public function updated(DailyCheckExtension $extension): void
    {
        if (! $extension->wasChanged(self::WATER_COLUMNS)) {
            return;
        }

        $this->alertIfCritical($extension);
    }

    private function alertIfCritical(DailyCheckExtension $extension): void
    {
        $critiques = collect($extension->getWaterAlerts())
            ->filter(fn ($a) => ($a['level'] ?? null) === 'critical')
            ->values()
            ->all();

        if ($critiques === []) {
            return;
        }

        // JAMAIS BLOQUANTE : un canal muet ne doit pas empêcher l'enregistrement du
        // pointage. Perdre le relevé serait pire que perdre l'alerte — c'est lui qui
        // porte la mesure.
        try {
            app(NotificationHub::class)->alertWaterQuality($extension, $critiques);
        } catch (\Throwable $e) {
            Log::warning("Alerte qualité d'eau non émise : {$e->getMessage()}");
        }
    }
}
