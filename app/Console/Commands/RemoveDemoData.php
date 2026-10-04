<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropRecipe;
use App\Models\Plot;
use App\Models\User;
use App\Support\DependencyGuard;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RETIRER LES DONNÉES DE DÉMONSTRATION — et rien d'autre.
 *
 * Le système sait exactement ce qu'il a semé ; il peut donc le retirer sans
 * toucher à une donnée réelle. Trois familles :
 *
 *   • les comptes de démonstration (`UserSeeder::USERS`), tous au mot de passe
 *     public « password » — dont deux administrateurs ;
 *   • le bâtiment « Bâtiment A » que sème `DatabaseSeeder` ;
 *   • les données de `CultureDemoSeeder` (campagne, parcelles, cycles, recette
 *     « Gari de manioc » et leurs saisies), si quelqu'un l'a lancé.
 *
 * ─── RECONNAÎTRE SANS SE TROMPER ───
 *
 * Un code comme « P-NORD » ou un nom comme « Bâtiment A », une vraie ferme peut
 * l'avoir choisi. Un élément n'est donc reconnu comme démonstration que s'il
 * porte TOUS les attributs semés (code ET nom, nom ET capacité ET type). Et un
 * élément reconnu mais qui porte un historique réel — un lot dans le bâtiment,
 * un cycle réel sur la parcelle — est GARDÉ, et dit pourquoi.
 *
 * Simulation par défaut. Le bâtiment part à la corbeille (restaurable).
 *
 * ─── TOUTES LES FERMES ───
 *
 * Les données de démonstration ne sont pas sur la ferme « courante » : la
 * culture de démo est semée sur la ferme par défaut, et « Bâtiment A » n'a
 * même AUCUNE ferme (semé avant que les fermes n'existent). La commande lit
 * donc sans le cloisonnement par ferme. Sa première version passait par ce
 * cloisonnement : elle ne trouvait rien hors de la ferme courante — c'est le
 * test, lancé sur le vrai semeur, qui l'a montré.
 */
class RemoveDemoData extends Command
{
    protected $signature = 'avismart:remove-demo-data
                            {--force : APPLIQUER le retrait. Sans ce drapeau : liste seule}';

    protected $description = 'Retire les données de démonstration semées (comptes, Bâtiment A, culture de démo) — simulation par défaut';

    /** Ce que `CultureDemoSeeder` sème, attribut pour attribut. */
    private const PARCELLES_DEMO = ['P-NORD' => 'Parcelle Nord', 'P-SUD' => 'Parcelle Sud', 'P-EST' => 'Parcelle Est'];

    private const CYCLES_DEMO = ['CY-MAIS-01', 'CY-MANIOC-01', 'CY-TOMATE-01'];

    /**
     * Ce qui FAIT PARTIE d'un élément, et part avec lui — à distinguer de
     * l'historique extérieur, qui le garde.
     *
     * La première version comptait tout rattachement comme un historique réel :
     * elle gardait « Parcelle Nord » à cause de ses relevés météo, et la recette
     * « Gari » à cause de… ses propres lignes. Une ligne de recette compose la
     * recette ; un relevé météo n'a de sens qu'avec sa parcelle — qu'il vienne
     * de la démonstration ou de `weather:fetch`, il n'est pas une donnée
     * d'exploitation.
     *
     * @var array<class-string, array<string, string>> modèle => [table => colonne]
     */
    private const CONTENU_PROPRE = [
        Plot::class       => ['weather_readings' => 'plot_id'],
        CropRecipe::class => ['crop_recipe_items' => 'crop_recipe_id'],
    ];

    public function handle(): int
    {
        $appliquer = (bool) $this->option('force');
        $this->line('');
        $this->line($appliquer
            ? '<options=bold>RETRAIT DES DONNÉES DE DÉMONSTRATION</>'
            : '<options=bold>RETRAIT DES DONNÉES DE DÉMONSTRATION</> — <fg=yellow>simulation, rien n’est modifié</>');

        $this->comptes($appliquer);
        $this->batimentA($appliquer);
        $this->cultureDemo($appliquer);

        $this->line('');
        $this->line($appliquer
            ? 'Terminé. Lancez `php artisan avismart:diagnostic` pour contrôler.'
            : 'Relancez avec <options=bold>--force</> pour appliquer.');

        return self::SUCCESS;
    }

    private function comptes(bool $appliquer): void
    {
        $comptes = User::withoutGlobalScopes()->whereIn('email', UserSeeder::emailsDeDemonstration())->get();

        if ($comptes->isEmpty()) {
            $this->line('  · Comptes de démonstration : aucun.');
            return;
        }

        foreach ($comptes as $compte) {
            $this->line("  · Compte de démonstration {$compte->email} : " . ($appliquer ? 'supprimé' : 'sera supprimé'));

            if ($appliquer) {
                $compte->revoquerLesAppareils();
                $compte->delete();
            }
        }
    }

    private function batimentA(bool $appliquer): void
    {
        $batiment = Building::withoutGlobalScopes()->where('name', 'Bâtiment A')->where('capacity', 3000)->where('type', 'chair')->first();

        if (! $batiment) {
            $this->line('  · « Bâtiment A » de démonstration : absent.');
            return;
        }

        if ($obstacles = DependencyGuard::blockers($batiment)) {
            $this->line('  · « Bâtiment A » : <fg=yellow>GARDÉ</> — il porte un historique ('
                . DependencyGuard::describe($obstacles) . '), c’est donc un bâtiment réel.');
            return;
        }

        $this->line('  · « Bâtiment A » de démonstration : ' . ($appliquer ? 'mis à la corbeille' : 'sera mis à la corbeille'));

        if ($appliquer) {
            $batiment->delete();
        }
    }

    private function cultureDemo(bool $appliquer): void
    {
        // Un cycle n'est « de démonstration » que si son code ET sa parcelle le sont.
        $parcelles = Plot::withoutGlobalScopes()->get()
            ->filter(fn ($p) => (self::PARCELLES_DEMO[$p->code] ?? null) === $p->name);

        $cycles = CropCycle::withoutGlobalScopes()->whereIn('code', self::CYCLES_DEMO)
            ->whereIn('plot_id', $parcelles->pluck('id'))
            ->get();

        if ($cycles->isEmpty() && $parcelles->isEmpty()) {
            $this->line('  · Données de culture de démonstration : absentes.');
            return;
        }

        $ids = $cycles->pluck('id')->all();

        // Les saisies rattachées aux cycles de démonstration : toute table qui
        // porte `crop_cycle_id`, trouvée dans le schéma plutôt que listée.
        $enfants = [];
        foreach (\App\Support\DataReset::tables() as $table) {
            if ($table !== 'crop_cycles' && Schema::hasColumn($table, 'crop_cycle_id')) {
                $n = DB::table($table)->whereIn('crop_cycle_id', $ids)->count();
                if ($n > 0) {
                    $enfants[$table] = $n;
                }
            }
        }

        foreach ($cycles as $cycle) {
            $this->line("  · Cycle de démonstration {$cycle->code} : " . ($appliquer ? 'supprimé' : 'sera supprimé'));
        }
        foreach ($enfants as $table => $n) {
            $this->line("      et ses saisies : {$n} × {$table}");
        }

        if ($appliquer) {
            DB::transaction(function () use ($enfants, $ids, $cycles) {
                foreach (array_keys($enfants) as $table) {
                    DB::table($table)->whereIn('crop_cycle_id', $ids)->delete();
                }
                $cycles->each->forceDelete();
            });
        }

        // Parcelles, campagne, recette : retirées seulement si plus rien de réel
        // ne s'y rattache une fois les cycles de démonstration partis.
        $restes = [
            ...$parcelles->all(),
            ...CropCampaign::withoutGlobalScopes()->where('code', 'like', 'CAM-%-GSP')->where('name', 'like', 'Grande saison pluies%')->get()->all(),
            ...CropRecipe::withoutGlobalScopes()->where('code', 'REC-GARI')->where('name', 'Gari de manioc')->get()->all(),
        ];

        foreach ($restes as $element) {
            $libelle = class_basename($element) . " {$element->code}";
            $contenu = self::CONTENU_PROPRE[$element::class] ?? [];
            $obstacles = array_diff_key(DependencyGuard::blockers($element), $contenu);

            // En simulation, les cycles de démonstration sont encore là : ils ne
            // doivent pas compter comme obstacles.
            if (! $appliquer) {
                unset($obstacles['crop_cycles'], $obstacles['crop_transformations']);
                foreach (array_keys($enfants) as $table) {
                    unset($obstacles[$table]);
                }
            }

            if ($obstacles) {
                $this->line("  · {$libelle} : <fg=yellow>GARDÉ</> — " . DependencyGuard::describe($obstacles) . ' réel(s) s’y rattachent.');
                continue;
            }

            $this->line("  · {$libelle} de démonstration : " . ($appliquer ? 'supprimé' : 'sera supprimé'));

            if ($appliquer) {
                DB::transaction(function () use ($element, $contenu) {
                    foreach ($contenu as $table => $colonne) {
                        DB::table($table)->where($colonne, $element->getKey())->delete();
                    }
                    method_exists($element, 'forceDelete') ? $element->forceDelete() : $element->delete();
                });
            }
        }
    }
}
