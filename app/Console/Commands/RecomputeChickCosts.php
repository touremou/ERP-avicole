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
 *   php artisan couvoir:recompute-chick-costs            # applique
 *   php artisan couvoir:recompute-chick-costs --dry-run  # simulation
 */
class RecomputeChickCosts extends Command
{
    protected $signature = 'couvoir:recompute-chick-costs {--dry-run : Simuler sans écrire}';

    protected $description = 'Recoûte les lots de poussinière du couvoir depuis le coût de revient de leur incubation.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

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
