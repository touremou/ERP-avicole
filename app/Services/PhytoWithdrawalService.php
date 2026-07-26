<?php

namespace App\Services;

use App\Models\CropInput;
use App\Models\Harvest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * CONFRONTATION TRAITEMENT PHYTO ↔ RÉCOLTE — le délai avant récolte, vérifié
 * après coup.
 *
 * `preharvest_days` était validé à trois points d'entrée puis silencieusement
 * jeté par l'Action : le champ n'était jamais enregistré, donc la garde de
 * délai avant récolte n'avait JAMAIS rien bloqué depuis son introduction. Le
 * stockage est corrigé, mais rien ne peut reconstituer le délai des traitements
 * déjà passés — il n'a jamais été écrit.
 *
 * Un audit automatique est donc impossible sur l'historique, et prétendre le
 * contraire serait mentir sur le niveau de garantie. Ce service fait la seule
 * chose honnête : lister les cas À CONFRONTER — chaque traitement
 * phytosanitaire suivi d'une récolte dans une fenêtre courte — pour que le
 * technicien reprenne la notice du produit et tranche cas par cas.
 *
 * Trois verdicts :
 *   depasse    le délai est connu (renseigné) ET la récolte tombe dedans
 *              → non-conformité établie, pas une hypothèse ;
 *   conforme   le délai est connu et la récolte est après l'échéance ;
 *   a_verifier le délai est INCONNU (traitement d'avant la correction)
 *              → confrontation manuelle avec la notice.
 */
class PhytoWithdrawalService
{
    /** Fenêtre par défaut : au-delà, la question ne se pose plus en pratique. */
    public const DEFAULT_WINDOW = 30;

    /**
     * @return array{
     *   window: int,
     *   rows: Collection,
     *   counts: array{depasse: int, a_verifier: int, conforme: int},
     *   treatments: int
     * }
     */
    public function confrontations(?Carbon $since = null, int $window = self::DEFAULT_WINDOW): array
    {
        $window = max(1, min($window, 120));
        $since = $since ?? now()->subMonths(6)->startOfDay();

        $treatments = CropInput::with('cropCycle.plot')
            ->where('type', 'phyto')
            ->whereDate('input_date', '>=', $since->toDateString())
            ->orderByDesc('input_date')
            ->get();

        $cycleIds = $treatments->pluck('crop_cycle_id')->filter()->unique();

        // Une seule requête pour toutes les récoltes concernées : la fenêtre est
        // large côté SQL, le tri fin se fait en mémoire par traitement.
        $harvests = Harvest::whereIn('crop_cycle_id', $cycleIds)
            ->whereDate('harvest_date', '>=', $since->copy()->subDays($window)->toDateString())
            ->orderBy('harvest_date')
            ->get()
            ->groupBy('crop_cycle_id');

        $rows = collect();

        foreach ($treatments as $treatment) {
            if (! $treatment->input_date || ! $treatment->crop_cycle_id) {
                continue;
            }

            $applied = $treatment->input_date->copy()->startOfDay();
            $limit = $applied->copy()->addDays($window);

            foreach ($harvests->get($treatment->crop_cycle_id, collect()) as $harvest) {
                $date = $harvest->harvest_date?->copy()->startOfDay();

                // Seules les récoltes APRÈS le traitement portent ses résidus.
                if (! $date || $date->lt($applied) || $date->gt($limit)) {
                    continue;
                }

                $gap = (int) $applied->diffInDays($date);

                $rows->push([
                    'treatment'   => $treatment,
                    'harvest'     => $harvest,
                    'cycle'       => $treatment->cropCycle,
                    'gap_days'    => $gap,
                    'dar'         => $treatment->preharvest_days ? (int) $treatment->preharvest_days : null,
                    'verdict'     => $this->verdict($treatment, $date),
                ]);
            }
        }

        // Le plus grave d'abord, puis l'incertain, puis le conforme — et à
        // verdict égal, l'écart le plus court, qui est le plus exposé.
        $order = ['depasse' => 0, 'a_verifier' => 1, 'conforme' => 2];
        $rows = $rows->sortBy(fn ($r) => [$order[$r['verdict']], $r['gap_days']])->values();

        return [
            'window'     => $window,
            'since'      => $since,
            'rows'       => $rows,
            'counts'     => [
                'depasse'    => $rows->where('verdict', 'depasse')->count(),
                'a_verifier' => $rows->where('verdict', 'a_verifier')->count(),
                'conforme'   => $rows->where('verdict', 'conforme')->count(),
            ],
            'treatments' => $treatments->count(),
        ];
    }

    private function verdict(CropInput $treatment, Carbon $harvestDate): string
    {
        if (! $treatment->preharvest_days) {
            // Délai jamais enregistré : on ne SAIT pas. On ne l'invente pas.
            return 'a_verifier';
        }

        return $treatment->blocksHarvest($harvestDate) ? 'depasse' : 'conforme';
    }
}
