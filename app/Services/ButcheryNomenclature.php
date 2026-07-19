<?php

namespace App\Services;

use App\Models\Species;

/**
 * Résolveur de nomenclature de boucherie multiespèces.
 *
 * Traduit la famille d'une espèce (Species::$family) en :
 *   - liste des morceaux de découpe adaptés (config/butchery.php → cuts) ;
 *   - bandes de rendement carcasse cible/alerte (→ carcass_yield).
 *
 * Pour la volaille, les bandes de rendement carcasse sont surchargées par les
 * paramètres Paramètres > Abattoir (abattoir.yield_target_min/max,
 * yield_alert_min) lorsqu'ils sont définis, pour préserver les réglages
 * historiques.
 */
class ButcheryNomenclature
{
    /**
     * Famille effective d'une espèce (repli config si non renseignée).
     */
    public static function familyFor(?Species $species): string
    {
        $family = $species?->family;
        $cuts = config('butchery.cuts', []);

        return ($family && isset($cuts[$family]))
            ? $family
            : config('butchery.default_family', 'volaille');
    }

    /**
     * Morceaux de découpe disponibles pour l'espèce.
     *
     * @return array<int, array{code:string,label:string,destination:string,default:bool}>
     */
    public static function cutsForSpecies(?Species $species): array
    {
        return static::cutsForFamily(static::familyFor($species));
    }

    /**
     * @return array<int, array{code:string,label:string,destination:string,default:bool}>
     */
    public static function cutsForFamily(string $family): array
    {
        $cuts = config("butchery.cuts.{$family}", config('butchery.cuts.volaille', []));

        return array_map(fn (array $c) => [
            'code'        => $c['code'],
            'label'       => $c['label'],
            'destination' => $c['destination'] ?? 'stock_frais',
            'default'     => (bool) ($c['default'] ?? false),
        ], $cuts);
    }

    /**
     * Codes de morceaux valides pour l'espèce (+ 'autre' libre, toujours admis).
     *
     * @return array<int, string>
     */
    public static function cutCodesForSpecies(?Species $species): array
    {
        $codes = array_column(static::cutsForSpecies($species), 'code');
        $codes[] = 'autre';

        return array_values(array_unique($codes));
    }

    /**
     * Bandes de rendement carcasse pour l'espèce.
     *
     * @return array{target_min:int,target_max:int,alert_min:int}
     */
    public static function carcassYieldForSpecies(?Species $species): array
    {
        $family = static::familyFor($species);

        $bands = config("butchery.carcass_yield.{$family}", [
            'target_min' => 70, 'target_max' => 75, 'alert_min' => 65,
        ]);

        // Rétrocompat volaille : les paramètres Abattoir priment s'ils existent.
        if ($family === 'volaille') {
            $bands = [
                'target_min' => (int) setting('abattoir.yield_target_min', $bands['target_min']),
                'target_max' => (int) setting('abattoir.yield_target_max', $bands['target_max']),
                'alert_min'  => (int) setting('abattoir.yield_alert_min', $bands['alert_min']),
            ];
        }

        return [
            'target_min' => (int) $bands['target_min'],
            'target_max' => (int) $bands['target_max'],
            'alert_min'  => (int) $bands['alert_min'],
        ];
    }

    /** Présentations disponibles (gammes de sortie carcasse), code => config. */
    public static function presentations(): array
    {
        return config('butchery.presentations', []);
    }

    /** Code de présentation par défaut (repli si non renseigné). */
    public static function defaultPresentation(): string
    {
        return (string) config('butchery.default_presentation', 'brut');
    }

    /** Configuration d'une présentation (repli sur le défaut si code inconnu). */
    public static function presentation(?string $code): array
    {
        $all = static::presentations();

        return $all[$code] ?? $all[static::defaultPresentation()] ?? [
            'label' => 'Brut', 'name' => 'Entier Frais', 'yield_delta' => 0, 'to_cut' => true,
        ];
    }

    /** Nom d'article de stock pour une présentation : « Poulet PAC », etc. */
    public static function presentationProductName(?string $code, ?Species $species): string
    {
        $speciesName = $species?->name_fr ?? 'Poulet';

        return trim("{$speciesName} " . static::presentation($code)['name']);
    }

    /**
     * Bande de rendement ATTENDUE pour une présentation = bande carcasse de
     * l'espèce décalée du yield_delta (l'effilé garde tête/pattes → rendement
     * plus haut). Sert à l'alerte d'écart à l'exécution.
     */
    public static function presentationYieldBand(?string $code, ?Species $species): array
    {
        $base  = static::carcassYieldForSpecies($species);
        $delta = (int) (static::presentation($code)['yield_delta'] ?? 0);

        return [
            'target_min' => $base['target_min'] + $delta,
            'target_max' => min(99, $base['target_max'] + $delta),
            'alert_min'  => $base['alert_min'] + $delta,
        ];
    }
}
