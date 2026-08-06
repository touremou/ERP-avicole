<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\User;
use App\Notifications\IndustrialAlert;
use Illuminate\Support\Facades\Log;

/**
 * FRANCHISSEMENT DU SEUIL DE MORTALITÉ CUMULÉE — déclaration UNIQUE.
 *
 * Cette alerte vivait dans BatchObserver::updated(), c'est-à-dire sur l'événement
 * Eloquent `updated` d'un lot dont l'effectif a changé.
 *
 * Or le pointage journalier — LE chemin par lequel la mortalité entre dans le
 * système, tous les jours, sur les deux sites — ne passe pas par Eloquent. Pour
 * éviter une boucle d'observateurs, DailyCheck::applyBatchImpact() écrit
 * l'effectif par une requête directe (`DB::table('batches')->update(...)`), qui
 * n'émet AUCUN événement. L'observateur ne se déclenchait donc jamais sur le
 * geste quotidien.
 *
 * Et le défaut se refermait sur lui-même. La condition d'alerte exige un
 * FRANCHISSEMENT : taux précédent sous le seuil, taux actuel au-dessus. Une fois
 * la ligne rouge passée en silence par accumulation de pointages, plus aucun
 * événement ultérieur ne pouvait alerter — le taux « précédent » était déjà
 * au-dessus. L'alerte de mortalité cumulée était donc non pas retardée, mais
 * DÉFINITIVEMENT muette dès que la dérive venait du terrain.
 *
 * Ce qui fonctionnait, et continue de fonctionner : l'alerte de PIC quotidien
 * (DailyCheck::checkDailyMortalitySpike), qui juge le taux du jour au regard de
 * la phase. Une hécatombe brutale était donc signalée ; une dérive lente vers
 * 5 % de mortalité cumulée — deux morts par jour pendant un mois — ne l'était
 * pas. C'est exactement l'inverse de ce qu'on attend d'un pilotage à distance :
 * le pic se voit à l'œil nu au bâtiment, la dérive ne se voit que dans les
 * chiffres.
 *
 * La règle est désormais déclarée ICI, et les DEUX chemins l'appellent avec
 * l'effectif d'avant. Aucun ne peut plus l'oublier sans qu'un test le dise.
 */
class CumulativeMortalityAlert
{
    /**
     * Évalue le franchissement et alerte si la ligne rouge vient d'être passée.
     *
     * @param  Batch  $batch             lot RAFRAÎCHI (effectif à jour)
     * @param  int    $previousQuantity  effectif AVANT l'écriture
     */
    public function evaluate(Batch $batch, int $previousQuantity): void
    {
        if ($batch->status !== Batch::STATUS_ACTIF || $batch->initial_quantity <= 0) {
            return;
        }

        $threshold = Batch::cumulativeMortalityThreshold();

        $current  = $this->rate($batch->initial_quantity, (int) $batch->current_quantity);
        $previous = $this->rate($batch->initial_quantity, $previousQuantity);

        // Franchissement seulement : sans cette condition, chaque pointage
        // au-dessus du seuil rejouerait l'alerte tous les jours jusqu'à la
        // clôture du lot, et l'éleveur cesserait de les lire.
        if (! ($current > $threshold && $previous <= $threshold)) {
            return;
        }

        $rate = round($current, 2);

        // Chaque canal est isolé : un WhatsApp en panne ne doit pas empêcher la
        // cloche, et réciproquement. C'est le seul intérêt d'une alerte.
        rescue(
            fn () => app(NotificationHub::class)->alertMortality(
                $batch,
                max(0, $batch->initial_quantity - (int) $batch->current_quantity),
                $rate
            ),
            fn ($e) => Log::warning("[Mortalité cumulée] Diffusion échouée pour {$batch->code} : {$e->getMessage()}"),
            report: false
        );

        $this->notifyAdmins($batch, $rate);
    }

    private function rate(int $initial, int $remaining): float
    {
        return (($initial - $remaining) / $initial) * 100;
    }

    /**
     * Filet de secours : la cloche et les canaux de l'administrateur, en plus des
     * préférences utilisateur gérées par NotificationHub.
     */
    private function notifyAdmins(Batch $batch, float $rate): void
    {
        try {
            // La relation est `userRole` (et non `role`) : l'ancien
            // `whereHas('role')` levait une BadMethodCallException avalée par le
            // catch, si bien que l'alerte n'était jamais envoyée.
            $admins = User::whereHas('userRole', fn ($q) => $q->where('name', 'admin'))->get();

            if ($admins->isEmpty()) {
                Log::warning("[Mortalité cumulée] Aucun compte de rôle 'admin' : alerte non envoyée pour {$batch->code}.");

                return;
            }

            $payload = [
                'type'         => 'high_mortality',
                'priority'     => 'high',
                'title'        => 'Alerte de Surmortalité',
                'message'      => "Mortalité critique franchie sur le lot {$batch->code} : {$rate}% atteint "
                                . "(effectif : {$batch->current_quantity}/{$batch->initial_quantity}).",
                'id_reference' => $batch->uuid,
            ];

            foreach ($admins as $admin) {
                rescue(
                    fn () => $admin->notify(new IndustrialAlert($payload)),
                    fn ($e) => Log::error("[Mortalité cumulée] Échec notification admin #{$admin->id} pour {$batch->code} : {$e->getMessage()}"),
                    report: false
                );
            }
        } catch (\Throwable $e) {
            Log::error("[Mortalité cumulée] Erreur globale sur {$batch->code} : {$e->getMessage()}");
        }
    }
}
