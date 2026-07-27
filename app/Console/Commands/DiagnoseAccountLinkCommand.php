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
    protected $signature = 'hr:diagnose-account {recherche? : Nom OU adresse e-mail, accents et casse indifférents (tous les comptes si omis)}';

    protected $description = "Explique pourquoi un compte ne trouve pas sa fiche employé";

    /** Minuscules sans accent : « TOURÉ » et « toure » deviennent comparables. */
    private function flatten(?string $value): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $value));
    }

    public function handle(): int
    {
        $needle = $this->argument('recherche');

        // Recherche sur le NOM **ou** l'adresse, accents et casse ignorés.
        //
        // La première version ne cherchait que dans l'e-mail : « touré » ne
        // renvoyait rien alors que le compte existait — on cherche
        // naturellement un agent par son nom, pas par son adresse. Et sous
        // Windows, l'accent tapé au terminal n'arrive pas toujours intact
        // jusqu'à PHP ; le filtrage se fait donc en mémoire sur une forme
        // désaccentuée, indépendante de la collation MySQL ou SQLite.
        $users = User::query()->orderBy('name')->get();

        if ($needle) {
            $flat = $this->flatten($needle);
            $users = $users->filter(fn (User $user) => str_contains($this->flatten($user->name), $flat)
                || str_contains($this->flatten($user->email), $flat))->values();
        }

        if ($users->isEmpty()) {
            $this->error($needle
                ? "Aucun compte ne correspond à « {$needle} » (recherche sur le nom ET l'adresse)."
                : 'Aucun compte.');
            $this->line('Lancez la commande SANS argument pour lister tous les comptes.');

            return self::FAILURE;
        }

        $this->line(($needle ? $users->count() . ' compte(s) trouvé(s) pour « ' . $needle . ' »' : $users->count() . ' compte(s)'));

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

        // FICHES EN DOUBLE. `users.email` est UNIQUE : deux COMPTES de connexion
        // ne peuvent pas partager une adresse. En revanche `employees.email` et
        // `employees.last_name` ne le sont pas — deux FICHES pour la même
        // personne sont donc possibles (saisie manuelle puis import, ou une fiche
        // par site). Comme `employees.user_id` EST unique, une seule peut porter
        // le lien : l'autre paraît « sans accès », et c'est ce qu'on prend pour un
        // conflit de comptes.
        $this->newLine();
        $this->line('<options=bold>FICHES EMPLOYÉ en double (même nom, ou même e-mail)</>');

        // toBase() : `merge()` sur une collection ELOQUENT traite ses éléments
        // comme des modèles et appelle getKey() sur les groupes. On travaille donc
        // sur des collections de base.
        $employees = Employee::withoutGlobalScopes()->get()->toBase();

        $groups = [];

        // Deux critères de rapprochement, car un doublon se reconnaît à l'un OU
        // à l'autre : même patronyme, ou même adresse.
        foreach ([
            fn (Employee $e) => $this->flatten($e->last_name . '|' . $e->first_name),
            fn (Employee $e) => filled($e->email) ? 'mail:' . $this->flatten($e->email) : null,
        ] as $key) {
            foreach ($employees->groupBy($key) as $signature => $group) {
                if ($signature === '' || $group->count() < 2) {
                    continue;
                }

                // Le même trio de fiches peut sortir des deux critères : on le
                // dédoublonne sur la liste d'identifiants, pas sur la clé.
                $ids = $group->pluck('id')->sort()->implode('-');
                $groups[$ids] = $group;
            }
        }

        if ($groups === []) {
            $this->line('   aucune');
        }

        foreach ($groups as $group) {
            $first = $group->first();
            $this->line("   {$first->last_name} {$first->first_name} — " . $group->count() . ' fiches :');

            foreach ($group as $employee) {
                $link = $employee->user_id
                    ? '<fg=green>rattachée au compte #' . $employee->user_id . '</>'
                    : '<fg=red>AUCUN compte</>';
                $this->line("      fiche #{$employee->id} ({$employee->employee_id}), ferme #{$employee->farm_id}, "
                    . "statut {$employee->status}" . ($employee->trashed() ? ' [ARCHIVÉE]' : '') . " — {$link}");
            }

            $this->line('   → Gardez UNE fiche : celle qui porte le lien et l\'historique (pointages, tâches, paie).');
            $this->line('     Archivez l\'autre depuis sa fiche. Si le lien est sur la MAUVAISE, déplacez-le avec');
            $this->line('     hr:relink-account --employee=<id de la bonne fiche> --user=<id du compte>.');
        }

        // Le cas voisin : deux comptes pour la même personne (noms identiques).
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
