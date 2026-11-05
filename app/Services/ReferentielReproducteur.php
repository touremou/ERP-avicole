<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\EggProduction;
use App\Models\Incubation;
use App\Models\ProductionNorm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * RÉFÉRENTIEL REPRODUCTEURS — RÉEL CONTRE GUIDE DE SOUCHE, SEMAINE PAR SEMAINE.
 *
 * Décision de l'exploitation (dindons reproducteurs ; vaut pour toute souche
 * dont la courbe porte les colonnes du guide, cf. 2026_11_02_000004). Pour
 * chaque semaine d'âge, le guide et ce que la bande a fait :
 *
 *   • ponte HD (%)          : moyenne des taux de ponte des collectes de la
 *                             semaine (le taux enregistré, sur les femelles) ;
 *   • œufs incubables (%)   : incubables ÷ œufs collectés — collectés moins
 *                             cassés, anormaux et autres déclassés
 *                             (EggProduction::incubables) ;
 *   • fertilité (%)         : Σ fertiles ÷ Σ œufs mis à couver, mirage fait ;
 *   • éclosion (%)          : Σ poussins éclos ÷ Σ œufs mis à couver —
 *                             Incubation::tauxPonderes, la règle du Couvoir
 *                             (incubations commencées dans la semaine) ;
 *   • aliment / eau         : par sujet présent et par jour pointé ;
 *   • mortalité + réforme   : % des sujets présents en début de semaine ;
 *   • poids                 : dernière pesée de la semaine (le pointage ne
 *                             sépare pas mâles et femelles : le guide affiche
 *                             les deux cibles, le réel la moyenne pesée).
 *
 * Alerte : ponte HD sous norme × 0,95 — 5 % relatifs
 * (ProductionNorm::ponteSousLaCourbe, la règle de l'analyse du matin).
 *
 * Les valeurs du guide ne sont PAS fournies : elles viennent du guide de la
 * souche, saisies ou importées dans Paramètres → Normes.
 */
class ReferentielReproducteur
{
    /**
     * @return array{semaines: array<int, array>, alerte: ?array, souche: ?string}|null
     *   null si la courbe de la bande ne porte pas de référentiel reproducteur.
     */
    public function comparer(Batch $batch, int $nombreDeSemaines = 8): ?array
    {
        $courbe = ProductionNorm::courbePour($batch);
        $debut  = $batch->debutDuCycle();

        if (! $debut || ! ProductionNorm::estReferentielReproducteur($courbe)) {
            return null;
        }

        $debut   = Carbon::parse($debut)->startOfDay();
        $derniere = $batch->semaineDuCycle();
        $premiere = max(1, $derniere - $nombreDeSemaines + 1);

        $pointages = $batch->dailyChecks()
            ->where('check_date', '>=', $debut->copy()->addDays(($premiere - 1) * 7)->toDateString())
            ->get();

        $semaines = [];
        for ($semaine = $premiere; $semaine <= $derniere; $semaine++) {
            $du = $debut->copy()->addDays(($semaine - 1) * 7);
            $au = $du->copy()->addDays(6);

            $semaines[] = [
                'semaine' => $semaine,
                'du'      => $du,
                'au'      => $au,
                'guide'   => ProductionNorm::interpoler($courbe, $semaine),
                'reel'    => $this->reel($batch, $pointages, $du, $au),
            ];
        }

        return [
            'souche'   => $batch->model_name,
            'semaines' => $semaines,
            'alerte'   => $this->alerteDePonte($batch, $semaines),
        ];
    }

    /** La dernière semaine dont la ponte est connue, jugée à la courbe. */
    private function alerteDePonte(Batch $batch, array $semaines): ?array
    {
        $jugee = collect($semaines)->reverse()
            ->first(fn ($s) => $s['reel']['laying'] !== null && ($s['guide']['laying'] ?? 0) > 0);

        if ($jugee === null) {
            return null;
        }

        $reel  = (float) $jugee['reel']['laying'];
        $guide = (float) $jugee['guide']['laying'];

        if (! ProductionNorm::ponteSousLaCourbe($batch, $reel, $guide)) {
            return null;
        }

        return [
            'semaine'   => $jugee['semaine'],
            'reel'      => round($reel, 1),
            'guide'     => round($guide, 1),
            'seuil'     => round(ProductionNorm::seuilDePonte($batch, $guide), 2),
            // Écart RELATIF à la norme, en % : la mesure de l'alerte.
            'ecart_pct' => round(($guide - $reel) / $guide * 100, 1),
        ];
    }

    private function reel(Batch $batch, Collection $pointages, Carbon $du, Carbon $au): array
    {
        $dansLaSemaine = $pointages->filter(function (DailyCheck $c) use ($du, $au) {
            $jour = Carbon::parse($c->check_date)->startOfDay();

            return $jour->betweenIncluded($du, $au);
        });

        // Œufs : le taux enregistré à chaque collecte (sur les femelles).
        // whereDate : une date stockée avec son heure (« 2026-11-02 00:00:00 »)
        // passe après « 2026-11-02 » en comparaison de chaînes — le dernier
        // jour de la semaine en sortait.
        $collectes = EggProduction::where('batch_id', $batch->id)
            ->whereDate('production_date', '>=', $du->toDateString())
            ->whereDate('production_date', '<=', $au->toDateString())
            ->get();
        $oeufs      = (int) $collectes->sum('total_eggs_collected');
        // Œufs incubables : posés à chaque collecte (EggProduction::incubables).
        $incubables = (int) $collectes->sum('incubable_eggs');

        // Incubations des œufs de la semaine (mises à couver dans la semaine).
        $couvees = Incubation::where('batch_id', $batch->id)
            ->whereDate('start_date', '>=', $du->toDateString())
            ->whereDate('start_date', '<=', $au->toDateString())
            ->get();
        $tauxCouvoir = Incubation::tauxPonderes($couvees);

        // Sujets présents : effectif d'aujourd'hui + les pertes survenues
        // depuis (les pointages ne datent pas l'effectif ; un reproducteur se
        // vend rarement en cours de cycle).
        $pertes = fn (Collection $c) => (int) $c->sum(fn (DailyCheck $p) => (int) $p->mortality + (int) $p->qty_sorted_out);
        $apres  = $pointages->filter(fn (DailyCheck $c) => Carbon::parse($c->check_date)->startOfDay()->gt($au));
        $finDeSemaine   = max(0, (int) $batch->current_quantity + $pertes($apres));
        $debutDeSemaine = $finDeSemaine + $pertes($dansLaSemaine);

        $joursPointes = $dansLaSemaine->count();
        $presents     = max(1, $finDeSemaine);

        $pesee = $batch->dernierePesee($dansLaSemaine);

        return [
            'laying'        => $collectes->isNotEmpty() ? round((float) $collectes->avg('laying_rate'), 1) : null,
            'hatching_eggs' => $oeufs > 0 ? round($incubables / $oeufs * 100, 1) : null,
            // La règle du Couvoir : pondérée par les œufs mis en incubation.
            'fertility'     => $tauxCouvoir['fertilite'],
            'hatchability'  => $tauxCouvoir['eclosion'],
            'feed'          => $joursPointes > 0
                ? round((float) $dansLaSemaine->sum('feed_consumed') * 1000 / ($joursPointes * $presents), 1) : null,
            'water'         => $joursPointes > 0 && (float) $dansLaSemaine->sum('water_consumed') > 0
                ? round((float) $dansLaSemaine->sum('water_consumed') * 1000 / ($joursPointes * $presents), 1) : null,
            'mortality'     => $joursPointes > 0 && $debutDeSemaine > 0
                ? round($pertes($dansLaSemaine) / $debutDeSemaine * 100, 2) : null,
            'weight'        => $pesee['poids_g'] ?? null,
        ];
    }
}
