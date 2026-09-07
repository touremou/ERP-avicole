<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordHatching
{
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            $hatched = (int) $data['hatched_chicks'];
            $fertile = (int) $incubation->fertile_eggs;

            /*
             * UN CYCLE CLOS NE S'ÉCLÔT PAS UNE SECONDE FOIS.
             *
             * Le mirage porte cette garde depuis toujours — « Impossible
             * d'effectuer un mirage sur un cycle clôturé » — et l'éclosion, qui
             * est pourtant LE GESTE QUI CLÔT, ne la portait pas.
             *
             * Ce n'était pas symbolique : les deux portes remettent, après
             * chaque éclosion, `chicks_dispatched` à 0 et `chicks_remaining` au
             * total. Re-soumettre le formulaire — retour arrière, double envoi
             * sur une connexion lente — sur un cycle dont 600 poussins sur 800
             * étaient DÉJÀ partis en dispatch ramenait le compteur à 0/800 :
             * les 600 poussins déjà répartis redevenaient « à dispatcher ».
             */
            if ($incubation->status === 'clos') {
                throw new \DomainException('Ce cycle est déjà clôturé : son éclosion a été enregistrée le '
                    . ($incubation->finished_at?->format('d/m/Y') ?? '—') . '.');
            }

            /*
             * LE TAUX EST DÉRIVÉ, ON NE L'ÉCRIT PAS.
             *
             * Cette écriture visait `hatchability_rate`. Elle n'écrivait rien :
             * la colonne est absente du `$fillable` d'`Incubation`, et
             * `update()` passe par `fill()`, qui jette sans un mot toute clé non
             * listée. Le taux d'éclosion sortait NULL en base à chaque clôture.
             *
             * C'est exactement ce qui était arrivé à `finished_at` — « une
             * écriture qui n'écrivait pas », dit le commentaire du `$fillable` —
             * corrigé en ajoutant cette seule colonne à la liste. Les deux taux
             * étaient dans le même cas, à trois lignes de là.
             *
             * On ne les ajoute pas pour autant : la déclaration vivante est
             * l'accesseur `Incubation::getHatchabilityRateAttribute` (éclos ÷
             * fertiles), exposé par `$appends`, que TOUS les écrans lisent déjà.
             * Une valeur dérivée stockée en double finit par diverger.
             */
            $incubation->update([
                'hatched_chicks' => $hatched,
                'status'         => 'clos',
                'finished_at'    => now(),
            ]);

            /*
             * La machine n'est libérée que si elle est RÉELLEMENT vide.
             *
             * Le statut était écrit sans condition : clôturer UN cycle basculait
             * l'incubateur en « Maintenance » alors qu'un autre cycle pouvait encore y être en
             * incubation (multi-étages). La machine s'affichait donc disponible avec
             * des œufs dedans — et le contrôle de capacité, qui s'appuie sur les
             * cycles en cours, restait juste pendant que l'écran mentait.
             */
            if ($incubation->incubator && $incubation->incubator->eggsInIncubation() === 0) {
                $incubation->incubator->update(['status' => 'Maintenance']);
            }

            return $incubation->fresh();
        });
    }
}
