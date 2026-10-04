<?php

namespace App\Support;

/**
 * Les textes du moteur hors-ligne web (resources/js/sync-*.js).
 *
 * Le JavaScript n'a pas accès à `__()`. Le layout dépose donc ces textes, déjà
 * traduits dans la langue de l'utilisateur, dans `window.AVISMART_TEXTES`, et
 * `traduire()` (sync-outcome.js) les y lit — avec la convention de toute
 * l'application : la clé EST le texte français.
 *
 * Une clé appelée par `traduire('…')` et absente d'ici resterait en français
 * pour tous : OfflineSyncTextsAreTranslatedTest le refuse.
 */
final class OfflineSyncTexts
{
    public const CLES = [
        // Bandeau
        ':n saisie(s) hors-ligne refusée(s) par le serveur — à ressaisir ou à corriger :',
        ':n saisie(s) hors-ligne d’un autre compte ou d’un autre site attendent sur ce navigateur. Elles partiront quand leur auteur se reconnectera sur le site où il les a saisies.',
        'Compris, retirer',
        'refusée',
        // Nature des saisies
        'lot',
        'pointage',
        'collecte d’œufs',
        'mouvement de stock',
        'vente',
        'dépense',
        // Motifs de repli, quand le serveur n'en donne pas
        'Refusée par le serveur.',
        'Droit insuffisant pour enregistrer cette saisie.',
        'Données invalides.',
        'Refusée par le serveur (HTTP :status).',
    ];

    /** @return array<string, string> texte français => texte dans la langue courante */
    public static function traduits(): array
    {
        return collect(self::CLES)->mapWithKeys(fn (string $cle) => [$cle => __($cle)])->all();
    }
}
