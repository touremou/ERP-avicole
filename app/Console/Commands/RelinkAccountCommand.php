<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan hr:relink-account --employee=12 --user=5` — déplacer le lien
 * compte ↔ fiche employé.
 *
 * `employees.user_id` porte une contrainte UNIQUE : un compte n'a au plus qu'UNE
 * fiche. Quand deux fiches existent pour la même personne (saisie manuelle puis
 * import, ou une fiche par site) et que le lien est sur la MAUVAISE — celle sans
 * historique — l'agent n'atteint pas ses tâches, et l'écran de gestion d'accès
 * ne propose pas de déplacer un lien existant : il ne sait que le créer.
 *
 * Cette commande le déplace, en refusant tout ce qui pourrait faire perdre des
 * données silencieusement. Elle ne supprime RIEN : archiver la fiche en trop
 * reste une décision humaine, prise depuis l'application.
 */
class RelinkAccountCommand extends Command
{
    protected $signature = 'hr:relink-account
        {--employee= : Identifiant de la fiche qui DOIT porter le lien}
        {--user= : Identifiant du compte de connexion}
        {--force : Ne pas demander confirmation}';

    protected $description = 'Déplace le lien compte ↔ fiche employé vers la bonne fiche';

    public function handle(): int
    {
        $employeeId = (int) $this->option('employee');
        $userId = (int) $this->option('user');

        if (! $employeeId || ! $userId) {
            $this->error('Indiquez --employee=<id> et --user=<id>.');
            $this->line('Trouvez les identifiants avec : php artisan hr:diagnose-account <nom>');

            return self::FAILURE;
        }

        $employee = Employee::withoutGlobalScopes()->find($employeeId);
        $user = User::find($userId);

        if (! $employee) {
            $this->error("Aucune fiche employé #{$employeeId}.");

            return self::FAILURE;
        }
        if (! $user) {
            $this->error("Aucun compte #{$userId}.");

            return self::FAILURE;
        }

        if ($employee->trashed()) {
            $this->error("La fiche #{$employeeId} est ARCHIVÉE : rattacher un compte à une fiche archivée le laisserait sans tâches. Restaurez-la d'abord.");

            return self::FAILURE;
        }

        // La fiche qui détient actuellement le lien de ce compte.
        $current = Employee::withoutGlobalScopes()->where('user_id', $user->id)->first();

        if ($current && $current->id === $employee->id) {
            $this->info("Le compte {$user->email} est DÉJÀ rattaché à la fiche #{$employee->id}. Rien à faire.");

            return self::SUCCESS;
        }

        // La fiche cible est-elle déjà prise par un AUTRE compte ? La contrainte
        // UNIQUE l'interdirait de toute façon : on le dit clairement plutôt que
        // de laisser remonter une erreur SQL.
        if ($employee->user_id && $employee->user_id !== $user->id) {
            $this->error("La fiche #{$employee->id} est déjà rattachée au compte #{$employee->user_id}. "
                . 'Détachez-le d\'abord, ou choisissez l\'autre fiche.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Compte  : {$user->email} (#{$user->id}, {$user->name})");
        $this->line("Fiche   : #{$employee->id} {$employee->employee_id} — {$employee->last_name} {$employee->first_name}, ferme #{$employee->farm_id}");
        if ($current) {
            $this->warn("Le lien sera RETIRÉ de la fiche #{$current->id} ({$current->employee_id}).");
            $this->line('   Cette fiche conserve tout son historique — pointages, tâches, paie. Seul l\'accès change.');
        }
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Déplacer le lien ?', false)) {
            $this->line('Annulé.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($current, $employee, $user) {
            // Libérer d'abord : la contrainte UNIQUE refuserait deux fiches
            // portant le même user_id, même l'instant d'une transaction.
            if ($current) {
                $current->forceFill(['user_id' => null])->save();
            }

            $employee->forceFill(['user_id' => $user->id])->save();
        });

        $this->info("Lien déplacé : {$user->email} → fiche #{$employee->id}.");
        $this->line('L\'agent doit se RECONNECTER sur le mobile pour que sa session reprenne la nouvelle fiche.');

        return self::SUCCESS;
    }
}
