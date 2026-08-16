<?php

namespace App\Console\Commands;

use App\Models\CcpRecord;
use App\Models\Farm;
use App\Models\SlaughterOrder;
use App\Models\TemperatureLog;
use App\Services\NotificationHub;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Contrôle de complétude des registres HACCP (spec §9) — planifié en fin
 * de journée :
 *
 *  - relevés de température du jour < N requis (Réglages
 *    abattoir.temp_readings_per_day) → alerte chef d'équipe/qualité ;
 *  - abattage exécuté aujourd'hui SANS relevé CCP 3 (refroidissement)
 *    → alerte : le point critique majeur du jour n'est pas tracé.
 *
 * Un registre incomplet découvert le soir se rattrape ; découvert par
 * l'inspecteur, il coûte l'agrément.
 */
class CheckHaccpRegisters extends Command
{
    protected $signature = 'haccp:check-registers';

    protected $description = 'Alerte sur les relevés HACCP manquants du jour (températures, CCP 3)';

    public function handle(NotificationHub $hub): int
    {
        $alerts = 0;

        /*
         * PAR SITE, ET NON EN BLOC.
         *
         * Le décompte des relevés se faisait `withoutGlobalScopes()` SANS
         * regrouper par ferme : les relevés de tous les sites étaient
         * additionnés, puis comparés à une exigence QUOTIDIENNE PAR SITE.
         *
         * Sur deux sites, deux relevés faits à Kindia satisfaisaient donc
         * l'exigence de Kérouané : le registre y restait vide et l'alerte ne
         * partait jamais. Plus il y a de sites, plus le contrôle est aveugle —
         * il suffit qu'UN seul travaille pour couvrir tous les autres.
         *
         * La commande sœur du même dossier le fait déjà correctement, et dit
         * pourquoi : « Chaque site a son propre pointage : une alerte globale
         * ne dirait pas OÙ la feuille manque, donc à qui la demander. »
         * (CheckAttendanceGaps). Un registre HACCP est nominatif par
         * établissement — c'est l'agrément du site qui en dépend.
         */
        $required = (int) setting('abattoir.temp_readings_per_day', 2);

        foreach (Farm::active()->get() as $farm) {
            // ── Relevés de température du jour, sur CE site ──
            $done = TemperatureLog::withoutGlobalScopes()
                ->where('farm_id', $farm->id)
                ->whereDate('releve_at', today())
                ->count();

            if ($required > 0 && $done < $required) {
                $this->sendAlert($hub,
                    "📋 {$farm->name} — registre des températures incomplet : {$done}/{$required} relevés "
                    . "effectués aujourd'hui. Compléter avant la fin de journée (exigence du plan de "
                    . 'maîtrise sanitaire).',
                    "Relevés de température manquants — {$farm->name}",
                );
                $alerts++;
            }

            // ── Abattages du jour sans CCP 3, sur CE site ──
            $executedToday = SlaughterOrder::withoutGlobalScopes()
                ->where('farm_id', $farm->id)
                ->whereDate('actual_date', today())
                ->whereIn('status', ['termine', 'bloque'])
                ->get();

            foreach ($executedToday as $order) {
                $hasCcp3 = CcpRecord::withoutGlobalScopes()
                    ->where('slaughter_order_id', $order->id)
                    ->where('ccp', CcpRecord::CCP3)
                    ->exists();

                if (! $hasCcp3) {
                    $this->sendAlert($hub,
                        "📋 {$farm->name} — ordre {$order->order_number} abattu aujourd'hui SANS relevé CCP 3 "
                        . '(température à cœur après refroidissement). Saisir le relevé avant la mise en stock.',
                        "CCP 3 non renseigné — {$order->order_number}",
                    );
                    $alerts++;
                }
            }
        }

        $this->info("haccp:check-registers — {$alerts} alerte(s) émise(s).");

        return self::SUCCESS;
    }

    private function sendAlert(NotificationHub $hub, string $message, string $title): void
    {
        try {
            $hub->alertHaccp($message, $title, 'normal');
        } catch (\Throwable $e) {
            Log::warning("haccp:check-registers : alerte non envoyée : {$e->getMessage()}");
        }
    }
}
