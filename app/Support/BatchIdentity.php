<?php

namespace App\Support;

use App\Models\ProductionNorm;
use App\Models\ProductionType;

/**
 * L'IDENTITÉ D'UNE BANDE TIENT ENSEMBLE : espèce, type de production, souche.
 *
 * Un même slug de type (« chair ») existe pour le poulet, la dinde, la caille,
 * la pintade, le canard et le pigeon ; une souche (« Dinde BUT 6 ») appartient à
 * une espèce. Les écrans filtraient ces listes par espèce, mais le serveur ne
 * vérifiait que leur EXISTENCE : une requête — ou un formulaire resté ouvert —
 * portant l'espèce poulet et le type « Dinde de chair » créait un poulet au
 * cycle et à l'aliment de la dinde.
 *
 * UNE déclaration, lue par toutes les portes qui créent ou modifient une bande :
 * création, modification, transfert, planification, synchronisation terrain.
 */
final class BatchIdentity
{
    /**
     * Erreurs d'incohérence, par champ (vide = cohérent).
     *
     * @return array<string, string>
     */
    public static function erreurs(?int $speciesId, ?int $productionTypeId, ?string $modelName): array
    {
        $erreurs = [];

        if ($speciesId && $productionTypeId) {
            $especeDuType = ProductionType::whereKey($productionTypeId)->value('species_id');

            if ($especeDuType && (int) $especeDuType !== $speciesId) {
                $erreurs['production_type_id'] = __("Ce type de production appartient à une autre espèce.");
            }
        }

        // Une souche CONNUE d'une autre espèce est refusée ; une souche générique
        // (sans espèce) ou un nom libre (« Non spécifié », souche locale) passe.
        if ($speciesId && $modelName) {
            $especeDeLaSouche = ProductionNorm::where('model_name', $modelName)
                ->whereNotNull('species_id')->value('species_id');

            if ($especeDeLaSouche && (int) $especeDeLaSouche !== $speciesId) {
                $erreurs['model_name'] = __("Cette souche appartient à une autre espèce.");
            }
        }

        return $erreurs;
    }
}
