<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le logo de l'entreprise était un simple champ texte où l'admin devait
 * recopier à la main un chemin (« logos/avismart.png »). On bascule ce
 * paramètre en type "image" pour proposer un vrai sélecteur de fichier
 * (upload) dans Paramètres > Général, géré par SettingsController.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'company_logo')
            ->whereNull('farm_id')
            ->update([
                'type'        => 'image',
                'label'       => 'Logo de l\'entreprise',
                'description' => 'Image affichée dans le menu et sur les documents imprimés (PNG, JPG, WEBP, SVG · max 2 Mo).',
                'unit'        => null,
                'updated_at'  => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'company_logo')
            ->whereNull('farm_id')
            ->update([
                'type'        => 'string',
                'label'       => 'Chemin du logo',
                'description' => 'Ex: logos/avismart.png (dans storage/app/public)',
                'unit'        => 'storage/...',
                'updated_at'  => now(),
            ]);
    }
};
