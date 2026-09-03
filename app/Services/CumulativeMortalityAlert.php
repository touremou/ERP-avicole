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
     * ─── LA MORTALITÉ N'EST PAS LA BAISSE D'EFFECTIF ───
     *
     * Le taux se calculait `(initial − current) / initial` : la CHUTE
     * D'EFFECTIF, pas les morts. Or l'effectif baisse aussi de tout ce qui sort
     * vivant — ventes de sujets vifs, expéditions, dispatch de poussins, départs
     * à l'abattoir, transferts entre lots.
     *
     * Mesuré, sur un lot de 1 000 sujets :
     *
     *   • 400 VENDUS, aucun mort → « Mortalité critique franchie : 40 % ».
     *     Une fausse alerte, sur le canal le plus grave de l'élevage ;
     *   • puis 80 morts RÉELS (8 %, largement au-dessus du seuil de 5 %) →
     *     AUCUNE alerte. La condition exige un franchissement, et la vente avait
     *     déjà mis le taux « précédent » au-dessus.
     *
     * Une vente allumait donc une fausse alarme, PUIS éteignait définitivement
     * la vraie sur ce lot — exactement le piège auto-refermant que l'en-tête de
     * ce fichier décrit pour le défaut d'origine, réintroduit par la grandeur
     * mesurée.
     *
     * Le modèle déclarait déjà la bonne : `Batch::total_mortality`
     * (qty_dead + Σ daily_checks.mortality + Σ mortality_infirmary) et le taux
     * `Batch::mortality_rate` qui en découle. Il y avait deux définitions du
     * « taux de mortalité cumulée » ; l'alerte lisait la mauvaise.
     *
     * @param  Batch  $batch              lot RAFRAÎCHI (mortalité à jour)
     * @param  int    $previousMortality  mortalité cumulée AVANT l'écriture
     */
    public function evaluate(Batch $batch, int $previousMortality): void
    {
        if ($batch->status !== Batch::STATUS_ACTIF || $batch->initial_quantity <= 0) {
            return;
        }

        // Même base que `Batch::mortality_rate` : les morts à l'arrivage ne sont
        // pas dans l'effectif initial, elles s'ajoutent au dénominateur.
        $base = (int) $batch->initial_quantity + (int) ($batch->qty_dead ?? 0);

        if ($base <= 0) {
            return;
        }

        $threshold = Batch::cumulativeMortalityThreshold();

        $current  = (float) $batch->mortality_rate;      // la déclaration du modèle
        $previous = $this->rate($base, $previousMortality);

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
                $batch->total_mortality,
                $rate
            ),
            fn ($e) => Log::warning("[Mortalité cumulée] Diffusion échouée pour {$batch->code} : {$e->getMessage()}"),
            report: false
        );

        $this->notifyAdmins($batch, $rate);
    }

    /** Taux de mortalité cumulée, même formule que `Batch::mortality_rate`. */
    private function rate(int $base, int $mortality): float
    {
        return round(($mortality / $base) * 100, 2);
    }

    /**
     * Filet de secours : la cloche et les canaux de l'administrateur, en plus des
     * préférences utilisateur gérées par NotificationHub.
     */
    private function notifyAdmins(Batch $batch, float $rate): void
    {
        try {
            // Déclaration UNIQUE de l'audience « administrateurs ». Ce bloc
            // portait la seule version SANS repli par role_id ni filtre sur les
            // comptes actifs — sur l'alerte la plus critique d'un élevage.
            //
            // Le commentaire d'origine gardait la trace d'une panne du même
            // ordre : `whereHas('role')` au lieu de `userRole` levait une
            // BadMethodCallException avalée par le catch ci-dessous, et l'alerte
            // n'était jamais envoyée. La résolution vit désormais en un seul
            // endroit, testé.
            $admins = \App\Support\Administrators::all();

            if ($admins->isEmpty()) {
                Log::warning("[Mortalité cumulée] Aucun compte de rôle 'admin' : alerte non envoyée pour {$batch->code}.");

                return;
            }

            $payload = [
                'type'         => 'high_mortality',
                'priority'     => 'high',
                'title'        => 'Alerte de Surmortalité',
                // On annonce les MORTS, pas l'effectif restant : celui-ci baisse
                // aussi des ventes et des transferts, et l'ancien libellé
                // « effectif : 600/1000 » invitait précisément à lire une vente
                // comme une hécatombe.
                'message'      => "Mortalité critique franchie sur le lot {$batch->code} : {$rate}% atteint "
                                . "({$batch->total_mortality} morts sur {$batch->initial_quantity} mis en place).",
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
