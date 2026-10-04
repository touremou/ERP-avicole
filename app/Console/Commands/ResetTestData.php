<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\DataReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * REMISE À ZÉRO DES DONNÉES DE TEST — avant la mise en service, jamais après.
 *
 * Vide tous les MOUVEMENTS (lots, pointages, ventes, dépenses, trésorerie,
 * paie…) et garde la configuration et les référentiels, remis d'aplomb.
 * Le classement table par table est dans `App\Support\DataReset`.
 *
 * ─── POURQUOI EN LIGNE DE COMMANDE, ET PAS UN BOUTON ───
 *
 * Un bouton qui efface tout est une arme pour quiconque dérobe une session
 * administrateur, et un mauvais clic permanent. La ligne de commande exige un
 * accès au serveur : c'est le bon niveau de privilège pour un geste
 * irréversible.
 *
 * ─── LES GARDE-FOUS ───
 *
 *   1. Simulation par défaut : le rapport dit ce qui partirait, ligne par table.
 *   2. Refus si une table n'est pas classée.
 *   3. Confirmation par le NOM de l'entreprise, saisi en toutes lettres.
 *   4. Sauvegarde de la base JUSTE AVANT ; si elle échoue, rien n'est touché.
 *   5. Une seule transaction : tout part, ou rien.
 *
 * ─── LA NUMÉROTATION REPART DE 1 ───
 *
 * Les numéros de facture, de BL, de dépense suivent le dernier document
 * existant (`DocumentNumberingService`). Les documents de test partis, la
 * première vraie facture portera le n° 1 — c'est ce qu'on veut avant la mise en
 * service. C'est aussi pourquoi on ne lance JAMAIS cette commande après avoir
 * remis de vraies factures : leurs numéros seraient réattribués.
 */
class ResetTestData extends Command
{
    protected $signature = 'avismart:reset-test-data
                            {--force : EFFACER pour de bon. Sans ce drapeau : simulation seule}
                            {--confirmer= : Nom de l’entreprise, pour confirmer sans saisie interactive}';

    protected $description = 'Vide les données de test (mouvements) et garde configuration et référentiels — simulation par défaut';

    /** Clé de conteneur de la sauvegarde préalable (remplaçable en test). */
    public const SAUVEGARDE = 'avismart.reset-test-data.sauvegarde';

    public function handle(): int
    {
        $this->line('');
        $this->line('<options=bold>REMISE À ZÉRO DES DONNÉES DE TEST</>');

        if ($nonClassees = DataReset::nonClassees()) {
            $this->error('Refusé : table(s) non classée(s) — ' . implode(', ', $nonClassees) . '.');
            $this->line('Chaque table doit être déclarée dans App\Support\DataReset (configuration, référentiel ou mouvement) avant toute remise à zéro.');

            return self::FAILURE;
        }

        $plan = DataReset::plan();
        $this->afficherPlan($plan);

        if (! $this->option('force')) {
            $this->line('');
            $this->line('<fg=yellow>Simulation — rien n’a été modifié.</> Relancez avec <options=bold>--force</> pour effacer.');

            return self::SUCCESS;
        }

        if (! $this->confirme()) {
            $this->error('Refusé : le nom saisi ne correspond pas. Rien n’a été modifié.');

            return self::FAILURE;
        }

        $this->line('Sauvegarde de la base avant effacement…');
        if (! app(self::SAUVEGARDE)()) {
            $this->error('Refusé : la sauvegarde a échoué. Rien n’a été modifié.');
            $this->line('Vérifiez `php artisan backup:run --only-db`, puis relancez.');

            return self::FAILURE;
        }

        $supprimees = DataReset::executer();
        $total = array_sum($supprimees);

        Log::warning("Remise à zéro des données de test : {$total} ligne(s) effacée(s).", $supprimees);
        if (function_exists('activity')) {
            activity()->withProperties(['lignes' => $supprimees])
                ->log("Remise à zéro des données de test ({$total} lignes)");
        }

        $this->line('');
        $this->info("Terminé : {$total} ligne(s) effacée(s) dans " . count($supprimees) . ' table(s).');
        $this->line('<options=bold>À faire maintenant :</>');
        foreach ($plan['aRessaisir'] as $point) {
            $this->line("  • {$point}");
        }
        $this->line('  • Puis : `php artisan avismart:diagnostic`.');

        return self::SUCCESS;
    }

    /** @param array{vider: array<string,int>, aplomb: array<int,string>, aRessaisir: array<int,string>} $plan */
    private function afficherPlan(array $plan): void
    {
        $total = array_sum($plan['vider']);

        $this->line('');
        $this->line("<options=bold>Seraient EFFACÉES</> : {$total} ligne(s)");
        if ($plan['vider'] === []) {
            $this->line('  (aucune — il n’y a pas de mouvement à effacer)');
        }
        foreach ($plan['vider'] as $table => $n) {
            $this->line(sprintf('  %-32s %8d', $table, $n));
        }

        $this->line('');
        $this->line('<options=bold>Seraient GARDÉS et remis d’aplomb</> : configuration, comptes, référentiels');
        foreach ($plan['aplomb'] as $regle) {
            $this->line("  • {$regle}");
        }

        $this->line('');
        $this->line('<fg=red;options=bold>À ne lancer QU’AVANT la mise en service</> : la numérotation des factures repart de 1.');
    }

    private function confirme(): bool
    {
        $attendu = trim((string) Setting::companyName());
        $saisi = $this->option('confirmer');

        if ($saisi === null) {
            $saisi = $this->ask("Pour confirmer, saisissez le nom de l’entreprise (« {$attendu} »)");
        }

        return $attendu !== '' && trim((string) $saisi) === $attendu;
    }
}
