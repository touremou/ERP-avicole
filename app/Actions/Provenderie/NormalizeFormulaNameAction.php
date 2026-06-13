<?php

namespace App\Actions\Provenderie;

use App\Models\Batch;
use App\Models\Formula;
use App\Models\Stock;
use Illuminate\Support\Facades\Log;

/**
 * Mappe une formule de provenderie vers le nom EXACT d'un article de stock
 * « conso » (aliment fini), afin que l'aliment produit alimente le bon silo
 * et soit consommable par les lots (cf. Batch::feedPhases()).
 *
 * Multiespèces : la cible n'est plus limitée à la volaille (Chair/Ponte).
 * Quand la formule connaît son type de production, on cible en priorité le
 * catalogue de phases de SON secteur (Engraissement, Laitière, Grossissement,
 * Alevinage, Reproducteur...). Les raccourcis volaille historiques restent
 * gérés pour rétrocompatibilité.
 */
class NormalizeFormulaNameAction
{
    /**
     * @param  string        $formulaName  Nom libre de la formule (ex: « CHAIR FINITION »)
     * @param  Formula|null  $formula      Formule source (pour connaître son secteur)
     * @return string                      Nom canonique d'article de stock
     */
    public function execute(string $formulaName, ?Formula $formula = null): string
    {
        $needle = $this->simplify($formulaName);
        $sector = $formula?->feedSector();

        // 1. Si le secteur est connu, on cherche la phase correspondante dans
        //    SON catalogue (toutes espèces confondues).
        if ($sector && isset(Batch::FEED_PHASES[$sector])) {
            $match = $this->matchPhaseInSector($needle, Batch::FEED_PHASES[$sector]);
            if ($match) {
                return $match;
            }
        }

        // 2. Raccourcis volaille historiques (uniquement si secteur inconnu ou
        //    volaille, pour ne pas détourner un aliment d'une autre espèce).
        if ($sector === null || in_array($sector, ['Chair', 'Ponte'], true)) {
            foreach ($this->legacyVolailleMappings() as $pattern => $stockName) {
                if (str_contains($needle, $pattern)) {
                    return $stockName;
                }
            }
        }

        // 3. Repli : article de stock dont le nom correspond exactement.
        $stock = Stock::where('item_name', $formulaName)
            ->where('category', Stock::CAT_CONSO)
            ->first();

        if ($stock) {
            return $stock->item_name;
        }

        // 4. Dernier recours : 1re phase du secteur connu, sinon nom brut.
        if ($sector && ! empty(Batch::FEED_PHASES[$sector])) {
            Log::warning(
                "[Provenderie] Formule '{$formulaName}' non mappée à une phase précise " .
                "du secteur {$sector}. Repli sur la 1re phase du secteur."
            );

            return Batch::FEED_PHASES[$sector][0];
        }

        Log::warning("[Provenderie] Nom de formule non mappé : '{$formulaName}'. Nom brut utilisé.");

        return $formulaName;
    }

    /**
     * Choisit, dans les phases d'un secteur, celle dont le descripteur
     * (nom sans le préfixe de secteur) recoupe le mieux le nom de la formule.
     *
     * @param  array<int, string> $phases
     */
    private function matchPhaseInSector(string $needle, array $phases): ?string
    {
        $best = null;
        $bestScore = 0;

        foreach ($phases as $phase) {
            // Descripteur = phase sans le mot de secteur (ex: « Laitière
            // Production » → « PRODUCTION »).
            $sectorWord = $this->simplify(explode(' ', $phase)[0]);
            $descriptor = trim(str_replace($sectorWord, '', $this->simplify($phase)));

            $score = 0;
            foreach (preg_split('/\s+/', $descriptor) as $token) {
                $token = trim($token);
                // On ignore les jetons trop courts (« 1 », « 2 », « DE »...).
                if (mb_strlen($token) < 3) {
                    continue;
                }
                if (str_contains($needle, $token)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $phase;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * Raccourcis volaille (noms abrégés) → article de stock canonique.
     *
     * @return array<string, string>
     */
    private function legacyVolailleMappings(): array
    {
        return [
            // Ponte
            'PONTE DEMARRAGE'  => 'Ponte Démarrage (Poussin)',
            'PONTE CROISSANCE' => 'Ponte Croissance (Poulette)',
            'PONTE 1'          => 'Ponte 1 (Pic de ponte)',
            'PONTE PIC'        => 'Ponte 1 (Pic de ponte)',
            'PONTE 2'          => 'Ponte 2 (Entretien)',
            'PONTE ENTRETIEN'  => 'Ponte 2 (Entretien)',

            // Chair
            'CHAIR DEMARRAGE'  => 'Chair Démarrage',
            'CHAIR CROISSANCE' => 'Chair Croissance',
            'CHAIR FINITION'   => 'Chair Finition',

            // Reproducteur volaille → aliment ponte
            'REPRO DEMARRAGE'  => 'Ponte Démarrage (Poussin)',
            'REPRO CROISSANCE' => 'Ponte Croissance (Poulette)',
        ];
    }

    /**
     * Normalise une chaîne pour comparaison : majuscules, sans accents,
     * espaces compactés.
     */
    private function simplify(string $value): string
    {
        $value = mb_strtoupper(trim($value));

        $accents = [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C',
        ];

        $value = strtr($value, $accents);

        return preg_replace('/\s+/', ' ', $value);
    }
}
