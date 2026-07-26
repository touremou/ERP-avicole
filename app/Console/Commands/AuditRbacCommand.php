<?php

namespace App\Console\Commands;

use App\Models\Module;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * `php artisan audit:rbac` — inventaire des trous de la matrice de droits.
 *
 * Le contrôle d'accès de cette application repose sur une convention : le
 * middleware `can:L|C|M|S` est un gate GÉNÉRIQUE, résolu au module de la route
 * par son PRÉFIXE DE NOM (Module::routePrefixMap). Cette indirection est
 * pratique mais silencieuse : deux façons de se tromper ne lèvent aucune erreur.
 *
 *  1. UN PRÉFIXE ABSENT DE LA CARTE. Le module n'est pas résolu, et le gate
 *     générique retombe alors sur « accès si AU MOINS UN module accorde ce
 *     niveau ». Une route de cultures devient accessible à un caissier. Le
 *     symptôme est invisible : la page s'ouvre, personne ne remarque rien.
 *  2. UNE ÉCRITURE GARDÉE PAR `can:L`. Le niveau L est la LECTURE : un simple
 *     lecteur peut alors modifier. C'est arrivé sur les préférences de
 *     notification et les envois de test (crédits SMS/e-mail réels).
 *
 * Cette commande sert au diagnostic à la main ; RbacRouteGuardTest verrouille
 * les mêmes règles en intégration pour empêcher la régression.
 */
class AuditRbacCommand extends Command
{
    protected $signature = 'audit:rbac {--strict : Code de sortie 1 si un écart bloquant est trouvé}';

    protected $description = 'Vérifie que chaque route web authentifiée est rattachée à un module et gardée au bon niveau';

    /** Hiérarchie des niveaux : une écriture doit exiger au moins C. */
    private const RANK = ['L' => 1, 'C' => 2, 'M' => 3, 'S' => 4];

    public function handle(): int
    {
        $findings = static::findings();

        $this->info('Audit RBAC des routes web');
        $this->newLine();

        $this->line('Préfixes de route à gate générique mais ABSENTS de routePrefixMap :');
        if ($findings['unmapped'] === []) {
            $this->line('   aucun');
        }
        foreach ($findings['unmapped'] as $prefix => $routes) {
            $this->line("   {$prefix}  (" . count($routes) . " route(s) — repli « n'importe quel module »)");
        }

        $this->newLine();
        $this->line('Écritures dont le niveau le plus élevé exigé est L (lecture) :');
        if ($findings['weak_writes'] === []) {
            $this->line('   aucune');
        }
        foreach ($findings['weak_writes'] as $route) {
            $this->line("   {$route}");
        }

        $this->newLine();
        $this->line('Écritures SANS gate de route — à revoir (garde interne au contrôleur ?) :');
        if ($findings['ungated_writes'] === []) {
            $this->line('   aucune');
        }
        foreach ($findings['ungated_writes'] as $route) {
            $this->line("   {$route}");
        }

        $blocking = $findings['unmapped'] !== [] || $findings['weak_writes'] !== [];

        $this->newLine();
        if ($blocking) {
            $this->error('Écarts bloquants détectés.');
        } else {
            $this->info('Aucun écart bloquant.');
        }

        return $this->option('strict') && $blocking ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Écarts constatés, exposés en statique pour être consommés par le test.
     *
     * `ungated_writes` est INFORMATIF : beaucoup de ces routes sont légitimement
     * sans gate de route (profil, déconnexion, actions personnelles) ou portent
     * leur contrôle dans le contrôleur — la licence en admin.S, le POS en
     * caisse.C, la passerelle de synchro qui délègue à SyncService (lequel
     * vérifie une gate par opération). On les liste pour la revue humaine, sans
     * en faire un échec : le test ne peut pas décider à la place du métier.
     *
     * @return array{
     *   unmapped: array<string, array<int, string>>,
     *   weak_writes: array<int, string>,
     *   ungated_writes: array<int, string>
     * }
     */
    public static function findings(): array
    {
        $map = Module::routePrefixMap();
        $unmapped = [];
        $weakWrites = [];
        $ungatedWrites = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            // Les routes d'API portent leurs gates dans SyncService et les
            // contrôleurs d'API (jetons Sanctum, pas de session) : hors périmètre.
            if (! $name || str_starts_with($name, 'api.')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            if (! in_array('auth', $middleware, true)) {
                continue;
            }

            $gates = [];
            foreach ($middleware as $one) {
                if (is_string($one) && str_starts_with($one, 'can:')) {
                    $gates[] = substr($one, 4);
                }
            }

            $isWrite = (bool) preg_match('/POST|PUT|PATCH|DELETE/', implode('|', $route->methods()));

            // Gates GÉNÉRIQUES (L/C/M/S sans point) : ce sont elles qui dépendent
            // de la résolution du module par préfixe de nom.
            $generic = array_values(array_filter($gates, fn ($g) => isset(self::RANK[$g])));

            if ($generic !== []) {
                $slug = null;
                foreach ($map as $prefix => $moduleSlug) {
                    if (str_starts_with($name, $prefix)) {
                        $slug = $moduleSlug;
                        break;
                    }
                }

                if ($slug === null) {
                    $unmapped[explode('.', $name)[0] . '.'][] = $name;
                }
            }

            if (! $isWrite) {
                continue;
            }

            if ($gates === []) {
                $ungatedWrites[] = $name;
                continue;
            }

            // Une écriture gardée UNIQUEMENT en L : niveau lecture, donc trou.
            // On retient le niveau le plus ÉLEVÉ exigé, car un groupe peut poser
            // can:L et la route escalader via middlewareFor (can:C/M/S) — c'est
            // le cas de formulas, daily-checks, employees… qui sont corrects.
            if ($generic !== []) {
                $strongest = max(array_map(fn ($g) => self::RANK[$g], $generic));
                if ($strongest === self::RANK['L']) {
                    $weakWrites[] = $name;
                }
            }
        }

        ksort($unmapped);
        sort($weakWrites);
        sort($ungatedWrites);

        return [
            'unmapped'       => $unmapped,
            'weak_writes'    => $weakWrites,
            'ungated_writes' => $ungatedWrites,
        ];
    }
}
