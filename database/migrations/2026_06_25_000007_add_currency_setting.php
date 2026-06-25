<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Devise paramétrable (general.currency) — remplace le « GNF » codé en dur.
 *
 * Le symbole monétaire était figé dans le code et les vues ; on l'expose comme
 * paramètre éditable (helper currency()/money()). Défaut : GNF (franc guinéen).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'general', 'key' => 'currency'],
            [
                'value'         => 'GNF',
                'type'          => 'select',
                'label'         => 'Devise',
                'options'       => 'GNF,XOF,XAF,USD,EUR',
                'display_order' => 8,
                'description'   => 'Symbole monétaire affiché dans toute l\'application',
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'general')->where('key', 'currency')->delete();
    }
};
