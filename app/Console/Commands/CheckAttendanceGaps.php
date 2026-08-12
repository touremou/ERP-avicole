<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Farm;
use App\Services\NotificationHub;
use App\Services\PayrollService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * POINTAGE MANQUANT — le trou qui coûte de l'argent en silence.
 *
 * La paie présume TRAVAILLÉ tout jour non pointé : c'est le bénéfice du doute,
 * choisi pour ne pas sanctionner un pointage incomplet. Sa conséquence, elle,
 * n'était dite nulle part : une période sans aucune feuille de présence produit
 * exactement la même paie qu'un mois de présence parfaite. Une absence d'une
 * semaine est donc payée, et rien ne le signale.
 *
 * Ce contrôle transforme cette règle d'angle mort en garde-fou. Il tourne le
 * SOIR, quand la journée se rattrape encore : découverte à la paie, l'information
 * n'a plus de valeur — on ne reconstitue pas un mois de présence de mémoire.
 *
 * Ce qu'il ne fait PAS, délibérément :
 *   • il ne bloque rien — le pointage reste un acte humain, pas une condition
 *     technique ; un blocage dur ferait saisir n'importe quoi pour débloquer ;
 *   • il ne crie pas le jour de REPOS hebdomadaire (rh.rest_day, cf.
 *     PayrollService::isRestDay) — une alerte qui sonne chaque dimanche cesse
 *     d'être lue, et le jour où elle compte, elle passe pour du bruit.
 */
class CheckAttendanceGaps extends Command
{
    protected $signature = 'hr:check-attendance {--days=3 : Nombre de jours ouvrés à contrôler}';

    protected $description = 'Alerte sur les jours ouvrés sans feuille de présence';

    public function handle(NotificationHub $hub): int
    {
        $lookback = max(1, (int) $this->option('days'));
        $alerts = 0;

        // Chaque site a son propre pointage : une alerte globale ne dirait pas
        // OÙ la feuille manque, donc à qui la demander.
        // Farm::active() : `withoutGlobalScopes()` incluait les sites SUPPRIMÉS, qui
        // recevaient donc des alertes de pointage manquant.
        $farms = Farm::active()->get();

        foreach ($farms as $farm) {
            $headcount = Employee::assignableInFarm($farm->id)->count();

            // Un site sans personnel affecté n'a rien à pointer : l'alerter
            // serait un faux positif permanent.
            if ($headcount === 0) {
                continue;
            }

            $missing = $this->missingWorkingDays($farm->id, $lookback);

            if (empty($missing)) {
                continue;
            }

            try {
                $hub->alertAttendanceMissing($farm->name, $missing, $headcount);
                $alerts++;
            } catch (\Throwable $e) {
                Log::warning("hr:check-attendance : alerte non envoyée ({$farm->name}) : {$e->getMessage()}");
            }
        }

        $this->info("hr:check-attendance — {$alerts} alerte(s) émise(s).");

        return self::SUCCESS;
    }

    /**
     * Jours OUVRÉS des N derniers jours sans aucune feuille de présence, du plus
     * ancien au plus récent.
     *
     * On remonte jour par jour jusqu'à avoir examiné N jours ouvrés — et non N
     * jours calendaires : sinon une semaine contenant le repos hebdomadaire
     * examinerait moins de jours travaillés qu'une autre, sans raison.
     *
     * @return array<int, string>
     */
    private function missingWorkingDays(int $farmId, int $workingDays): array
    {
        $missing = [];
        $examined = 0;
        $day = today();

        // Garde-fou de boucle : avec « aucun » jour de repos, chaque jour compte,
        // mais un réglage inattendu ne doit pas faire tourner à l'infini.
        $guard = $workingDays * 7 + 7;

        while ($examined < $workingDays && $guard-- > 0) {
            $day = $day->copy()->subDay();

            if (PayrollService::isRestDay($day)) {
                continue;
            }

            $examined++;

            $pointed = EmployeeAttendance::withoutGlobalScopes()
                ->where('farm_id', $farmId)
                ->whereDate('attendance_date', $day->toDateString())
                ->exists();

            if (! $pointed) {
                $missing[] = $day->toDateString();
            }
        }

        // Du plus ancien au plus récent : on lit un retard dans l'ordre où il
        // s'est accumulé.
        return array_reverse($missing);
    }
}
