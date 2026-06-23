<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Palier P1 « Clarté & adoption » du module Ressources :
 *
 * 1. RENOMMAGE — le module s'appelait « Ressources » (ambigu). On le rebaptise
 *    « Eau & Énergie » (libellé fonctionnel explicite). Le slug RBAC reste
 *    `ressources` : aucune permission ni route n'est cassée.
 *
 * 2. TÂCHES DE RELEVÉ — deux templates quotidiens au niveau ferme :
 *    « Relevé eau » (releve_eau) et « Relevé énergie » (releve_energie). Le
 *    relevé devient une tâche planifiée, assignée et traçable. La saisie d'un
 *    relevé clôt automatiquement la tâche (ReleveTaskService). Catégories
 *    dédiées : un pointage volaille (controle) n'y touche pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Renommage du module (idempotent).
        DB::table('modules')->where('slug', 'ressources')->update([
            'name'        => 'Eau & Énergie',
            'description' => 'Suivi de l\'eau, de l\'énergie et du carburant : continuité de service et maîtrise des coûts.',
            'updated_at'  => now(),
        ]);

        // 2. Templates de relevé (idempotents).
        foreach ($this->templates() as $tpl) {
            $exists = DB::table('task_templates')
                ->where('name', $tpl['name'])
                ->where('category', $tpl['category'])
                ->exists();

            if (! $exists) {
                DB::table('task_templates')->insert(array_merge([
                    'farm_id'          => null,
                    'description'      => null,
                    'days_of_week'     => null,
                    'day_of_month'     => null,
                    'duration_minutes' => 10,
                    'target_type'      => 'farm',
                    'per_building'     => false,
                    'batch_types'      => null,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ], $tpl));
            }
        }
    }

    public function down(): void
    {
        DB::table('modules')->where('slug', 'ressources')->update([
            'name'       => 'Ressources',
            'updated_at' => now(),
        ]);

        DB::table('task_templates')
            ->whereIn('category', ['releve_eau', 'releve_energie'])
            ->delete();
    }

    private function templates(): array
    {
        return [
            [
                'name'           => 'Relevé eau',
                'category'       => 'releve_eau',
                'icon'           => 'fa-droplet',
                'color'          => 'cyan',
                'frequency'      => 'quotidien',
                'scheduled_time' => '07:00',
                'priority'       => 'normale',
            ],
            [
                'name'           => 'Relevé énergie',
                'category'       => 'releve_energie',
                'icon'           => 'fa-bolt',
                'color'          => 'amber',
                'frequency'      => 'quotidien',
                'scheduled_time' => '07:00',
                'priority'       => 'normale',
            ],
        ];
    }
};
