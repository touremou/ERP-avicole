<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan hr:diagnose-account [email]` — pourquoi un compte ne trouve pas
 * sa fiche employé.
 *
 * Le symptôme est déroutant : le web affiche la fiche, le mobile répond « votre
 * compte n'est pas rattaché à une fiche employé ». Trois causes possibles, et
 * elles ne se distinguent pas depuis l'écran :
 *
 *   1. la fiche est rattachée à une AUTRE ferme que celle résolue (corrigé :
 *      User::employee() ignore désormais le scope de ferme) ;
 *   2. il existe DEUX comptes pour la même personne, et seul l'un porte le lien
 *      users → employees.user_id ;
 *   3. la fiche existe mais son user_id est NULL — jamais rattachée.
 *
 * Cette commande lit la base SANS AUCUN scope et dit laquelle des trois. On ne
 * peut pas deviner à distance : il faut regarder.
 */
class DiagnoseAccountLinkCommand extends Command
{
    protected $signature = 'hr:diagnose-account {email? : Adresse du compte (toutes si omis)}';

    protected $description = "Explique pourquoi un compte ne trouve pas sa fiche employé";

    public function handle(): int
    {
        $email = $this->argument('email');

        $users = User::query()
            ->when($email, fn ($q) => $q->where('email', 'like', "%{$email}%"))
            ->orderBy('email')
            ->get();

        if ($users->isEmpty()) {
            $this->error($email ? "Aucun compte ne correspond à « {$email} »." : 'Aucun compte.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $this->newLine();
            $this->line("<options=bold>{$user->email}</> — compte #{$user->id} ({$user->name})");

            // Fermes du compte.
            $farms = DB::table('farm_user')
                ->join('farms', 'farms.id', '=', 'farm_user.farm_id')
                ->where('farm_user.user_id', $user->id)
                ->select('farms.id', 'farms.name', 'farm_user.is_default')
                ->get();

            $this->line('   Fermes accessibles : ' . ($farms->isEmpty()
                ? '<fg=red>AUCUNE (farm_user vide)</>'
                : $farms->map(fn ($f) => "#{$f->id} {$f->name}" . ($f->is_default ? ' (défaut)' : ''))->join(', ')));

            // Fiche(s) employé — sans scope, sinon on reproduirait le bug.
            $employees = Employee::withoutGlobalScopes()->where('user_id', $user->id)->get();

            if ($employees->isEmpty()) {
                $this->line('   Fiche employé   : <fg=red>AUCUNE</> — ce compte n\'a pas de lien users → employees.user_id.');
                $this->line('   → Ses tâches personnelles ne peuvent pas apparaître. Créez l\'accès DEPUIS la fiche employé');
                $this->line('     (Personnel › fiche › Gérer l\'accès), ou rattachez la fiche existante à ce compte.');
                continue;
            }

            foreach ($employees as $employee) {
                $archived = $employee->trashed() ? ' <fg=red>[ARCHIVÉE]</>' : '';
                $this->line("   Fiche employé   : <fg=green>#{$employee->id}</> {$employee->employee_id} — "
                    . "{$employee->last_name} {$employee->first_name}, ferme #{$employee->farm_id}, statut {$employee->status}{$archived}");
            }

            if ($employees->count() > 1) {
                $this->warn('   Plusieurs fiches pour ce compte : la ferme courante décide laquelle est retenue.');
            }
        }

        // Le cas le plus trompeur : deux comptes pour la même personne.
        $this->newLine();
        $this->line('<options=bold>Comptes en DOUBLE (même nom, adresses différentes)</>');
        $duplicates = User::query()
            ->select('name', DB::raw('COUNT(*) as n'))
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->line('   aucun');
        }

        foreach ($duplicates as $duplicate) {
            $accounts = User::where('name', $duplicate->name)->get();
            $this->line("   {$duplicate->name} :");
            foreach ($accounts as $account) {
                $linked = Employee::withoutGlobalScopes()->where('user_id', $account->id)->exists();
                $this->line("      {$account->email} (#{$account->id}) — fiche employé : "
                    . ($linked ? '<fg=green>oui</>' : '<fg=red>NON</>'));
            }
            $this->line('   → Si vous vous connectez au mobile avec le compte SANS fiche, le symptôme est exactement');
            $this->line('     celui décrit. Utilisez le même compte des deux côtés, ou rattachez la fiche au second.');
        }

        return self::SUCCESS;
    }
}
