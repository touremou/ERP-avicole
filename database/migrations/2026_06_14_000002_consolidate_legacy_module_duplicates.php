<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolide les modules DOUBLONS legacy dans leurs équivalents canoniques.
 *
 * Contexte : la migration 2026_06_04_000001 seede les modules canoniques
 * (annuaire, logistique, notifications, …) tandis qu'une ancienne version de
 * ModuleSeeder créait des slugs DIFFÉRENTS (rh, couvoir, stocks). Comme les
 * slugs diffèrent, `updateOrCreate(['slug' => …])` n'a pas mis à jour les
 * lignes existantes mais INSÉRÉ des doublons. Conséquences :
 *   - la « Matrice des modules » affiche RH / Couvoir / Stocks à côté de
 *     Annuaire / Production / Logistique ;
 *   - une permission accordée sur `rh` n'est JAMAIS lue par le code, qui
 *     contrôle `annuaire.*` → un opérateur avec « rh.L » n'a pas accès à
 *     l'Annuaire ni à la génération de tâches.
 *
 * Correctif : pour chaque doublon, on FUSIONNE ses permissions (OR logique,
 * pour ne dégrader aucun rôle) dans le module canonique cible, puis on
 * supprime le doublon. Idempotent : si les doublons n'existent pas (base
 * fraîche déjà propre), la migration ne fait rien.
 */
return new class extends Migration {
    /** Doublon legacy (slug) → module canonique cible (slug). */
    private array $map = [
        'rh'      => 'annuaire',
        'couvoir' => 'production',
        'stocks'  => 'logistique',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $hasPivot = Schema::hasTable('module_permissions');
        $now = now();

        foreach ($this->map as $legacySlug => $canonicalSlug) {
            $legacy = DB::table('modules')->where('slug', $legacySlug)->first();
            if (! $legacy) {
                continue; // déjà nettoyé
            }

            $canonical = DB::table('modules')->where('slug', $canonicalSlug)->first();

            // Sécurité : si la cible canonique manque (cas anormal), on se
            // contente de promouvoir le doublon au slug canonique plutôt que
            // de détruire des données.
            if (! $canonical) {
                DB::table('modules')->where('id', $legacy->id)->update([
                    'slug'       => $canonicalSlug,
                    'updated_at' => $now,
                ]);
                continue;
            }

            if ($hasPivot) {
                foreach (DB::table('module_permissions')->where('module_id', $legacy->id)->get() as $perm) {
                    $target = DB::table('module_permissions')
                        ->where('role_id', $perm->role_id)
                        ->where('module_id', $canonical->id)
                        ->first();

                    if ($target) {
                        // Fusion non destructive : OR sur chaque droit.
                        DB::table('module_permissions')->where('id', $target->id)->update([
                            'can_read'   => (bool) ($target->can_read   || $perm->can_read),
                            'can_create' => (bool) ($target->can_create || $perm->can_create),
                            'can_modify' => (bool) ($target->can_modify || $perm->can_modify),
                            'can_delete' => (bool) ($target->can_delete || $perm->can_delete),
                            'updated_at' => $now,
                        ]);
                    } else {
                        DB::table('module_permissions')->insert([
                            'role_id'    => $perm->role_id,
                            'module_id'  => $canonical->id,
                            'can_read'   => $perm->can_read,
                            'can_create' => $perm->can_create,
                            'can_modify' => $perm->can_modify,
                            'can_delete' => $perm->can_delete,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                DB::table('module_permissions')->where('module_id', $legacy->id)->delete();
            }

            DB::table('modules')->where('id', $legacy->id)->delete();
        }
    }

    public function down(): void
    {
        // Pas de rollback : la fusion des permissions et la suppression des
        // doublons ne sont pas réversibles sans perte d'information.
    }
};
