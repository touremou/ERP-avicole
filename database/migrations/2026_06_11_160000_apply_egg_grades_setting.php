<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le paramètre production.egg_grades est désormais réellement appliqué :
 * il pilote la liste ET l'ordre des calibres affichés/utilisés partout
 * (formulaire de tri, KPI, validations, stock) via EggProduction::activeGrades().
 *
 * On normalise sa valeur sur l'ordre historique d'affichage (XL → S, le plus
 * gros calibre en premier) pour ne pas changer le comportement visuel existant,
 * et on clarifie le libellé/description.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('group', 'production')
            ->where('key', 'egg_grades')
            ->whereNull('farm_id')
            ->update([
                'value'       => 'XL,L,M,S',
                'label'       => 'Calibres œufs actifs (ordre d\'affichage)',
                'description' => 'Calibres standard parmi XL, L, M, S, séparés par des virgules. Pilote la liste et l\'ordre utilisés dans le tri, les KPI et le stock.',
                'updated_at'  => now(),
            ]);

        // La valeur est modifiée directement en base : on invalide le cache des
        // paramètres pour que setting('production.egg_grades') reflète le changement.
        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'production')
            ->where('key', 'egg_grades')
            ->whereNull('farm_id')
            ->update([
                'value'       => 'S,M,L,XL',
                'label'       => 'Calibres œufs (séparés par ,)',
                'description' => null,
                'updated_at'  => now(),
            ]);

        // La valeur est modifiée directement en base : on invalide le cache des
        // paramètres pour que setting('production.egg_grades') reflète le changement.
        Setting::clearCache();
    }
};
