<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * Rappel quotidien sur les contrats à terme qui arrivent à échéance — ou qui
 * l'ont dépassée sans décision.
 *
 * L'écran de suivi ne sert qu'à celui qui l'ouvre. Le promoteur vit à
 * l'étranger : c'est l'alerte qui doit venir à lui, pas lui qui doit penser à
 * aller voir. Fenêtre réglable par rh.contract_notice_days.
 */
class CheckContractExpiry extends Command
{
    protected $signature = 'hr:check-contracts';

    protected $description = 'Alerte sur les CDD/Journaliers arrivant à terme ou déjà dépassés sans décision';

    public function handle(NotificationHub $hub): int
    {
        $days = (int) setting('rh.contract_notice_days', 30);

        $employees = Employee::contractsToDecide($days)->get();

        // Contrats à terme SANS terme : ils n'entrent dans aucune fenêtre, donc
        // l'alerte d'échéance ne les verrait jamais. Ce sont pourtant les plus
        // exposés — ils échappent totalement au suivi. On les signale à part.
        $missing = Employee::missingContractTerm()->get();

        $hub->alertContractsToDecide($employees, $missing);

        $this->info("{$employees->count()} contrat(s) à terme signalé(s).");
        $this->info("{$missing->count()} contrat(s) sans terme renseigné.");

        return self::SUCCESS;
    }
}
