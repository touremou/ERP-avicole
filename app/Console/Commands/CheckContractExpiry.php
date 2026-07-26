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

        $hub->alertContractsToDecide($employees);

        $this->info("{$employees->count()} contrat(s) à terme signalé(s).");

        return self::SUCCESS;
    }
}
