<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\ChickDispatch;
use Illuminate\Console\Command;

/**
 * Recoûte les lots de poussinière issus du couvoir à partir du coût de revient
 * de leur incubation (process costing). Utile en reprise : les lots créés avant
 * la mise en place du coût des œufs portaient un coût d'acquisition nul.
 *
 * Prérequis : l'incubation source doit porter un coût (egg_unit_cost / overhead).
 * Les incubations sans coût sont ignorées (rien à répercuter).
 *
 *   php artisan couvoir:recompute-chick-costs           # SIMULATION (rien n'est écrit)
 *   php artisan couvoir:recompute-chick-costs --force   # applique
 *
 * ─── POURQUOI LA CONVENTION A CHANGÉ ───
 *
 * Cette commande APPLIQUAIT par défaut, `--dry-run` servant à simuler. Deux autres
 * commandes qui réécrivent des chiffres comptables — feed:recompute-costs et
 * stocks:sync — font l'inverse : elles simulent par défaut et n'écrivent qu'avec
 * --force.
 *
 * Le même geste, `php artisan <commande>`, montrait donc les corrections dans un cas
 * et réécrivait les comptes dans l'autre, selon celle qu'on avait tapée. Quelqu'un
 * qui a appris « on lance pour voir, puis --force » sur l'une réécrivait ses coûts
 * sans le vouloir avec l'autre. C'est la même famille de défaut que le reste de cet
 * audit : une règle — ici une convention de sûreté — déclarée de deux façons
 * opposées.
 *
 * `--dry-run` reste ACCEPTÉ, et vaut le comportement par défaut : une habitude ou un
 * script existant ne doit pas se mettre à écrire du jour au lendemain.
 */
class RecomputeChickCosts extends Command
{
    protected $signature = 'couvoir:recompute-chick-costs
                            {--force : ÉCRIRE les corrections. Sans ce drapeau : simulation seule}
                            {--dry-run : Conservé pour compatibilité — c\'est déjà le comportement par défaut}';

    protected $description = 'Recoûte les lots de poussinière du couvoir depuis le coût de revient de leur incubation.';

    public function handle(): int
    {
        // Simulation par défaut. --dry-run est toléré et redondant (cf. l'en-tête).
        $dry = ! $this->option('force');

        if ($dry) {
            $this->warn('Simulation : aucune écriture. Ajouter --force pour appliquer.');
        }

        // Console : pas de ferme en session → on traite toutes les fermes.
        $dispatches = ChickDispatch::withoutGlobalScopes()
            ->where('destination_type', 'elevage')
            ->whereNotNull('batch_id')
            ->with('incubation')
            ->get();

        $updated = 0;
        $skipped = 0;

        foreach ($dispatches as $dispatch) {
            $unit = $dispatch->incubation?->chickUnitCost() ?? 0.0;

            if ($unit <= 0) {
                $skipped++;
                continue; // incubation sans coût → rien à répercuter
            }

            $batch = Batch::withoutGlobalScopes()->find($dispatch->batch_id);
            if (! $batch) {
                $skipped++;
                continue;
            }

            $total = round((float) $dispatch->quantity * $unit, 2);

            $this->line(sprintf(
                '%s  %s : %d poussins × %s = %s',
                $dry ? '[DRY]' : '  →  ',
                $batch->code,
                (int) $dispatch->quantity,
                number_format($unit, 2, ',', ' '),
                number_format($total, 2, ',', ' ')
            ));

            if (! $dry) {
                $batch->update([
                    'buy_price_per_unit'     => $unit,
                    'total_acquisition_cost' => $total,
                ]);
            }

            $updated++;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Lots recoûtés : {$updated} — ignorés (sans coût) : {$skipped}.");

        return self::SUCCESS;
    }
}
