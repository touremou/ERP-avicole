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
            
            $hatchabilityRate = $fertile > 0 ? ($hatched / $fertile) * 100 : 0;

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

            $incubation->update([
                'hatched_chicks'    => $hatched,
                'hatchability_rate' => $hatchabilityRate,
                'status'            => 'clos',
                'finished_at'       => now()
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



/* namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordHatching
{
    
     //* Exécute l'enregistrement de l'éclosion et clôture le cycle.
     
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            $hatched = (int) $data['hatched_chicks'];
            $fertile = (int) $incubation->fertile_eggs;
            
            // Calcul du taux d'éclosabilité
            $hatchabilityRate = $fertile > 0 ? ($hatched / $fertile) * 100 : 0;

            // 1. Clôture de l'incubation
            $incubation->update([
                'hatched_chicks'    => $hatched,
                'status'            => 'clos' // Le taux sera probablement un accessoire ou géré par un Observer plus tard
            ]);

            // 2. Gestion de l'infrastructure (Machine)
            //
            // Même règle que l'autre chemin de clôture de ce fichier : la machine ne
            // part au nettoyage que si elle est RÉELLEMENT vide. Sans cette
            // condition, clôturer un cycle envoyait en « Maintenance » une machine
            // portant encore un autre cycle en incubation.
            if ($incubation->incubator && $incubation->incubator->eggsInIncubation() === 0) {
                // Rigueur : Une machine sortant d'un cycle DOIT passer par un nettoyage/désinfection
                $incubation->incubator->update(['status' => 'Maintenance']);
            }

            // [OPTIONNEL FUTUR] 3. Intégration Stock : Créer automatiquement le lot de poussins ou l'entrée en stock
            // app(StockIntegrationService::class)->addChicksToStock($hatched, $incubation->batch_id);

            return $incubation->fresh();
        });
    }
} */