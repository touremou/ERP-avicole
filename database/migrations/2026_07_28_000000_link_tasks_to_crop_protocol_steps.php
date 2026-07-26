<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1 — L'itinéraire technique doit PILOTER le calendrier, pas seulement alerter.
 *
 * État corrigé : CropProtocolAlertService projette bien les étapes en jours après
 * semis (« traitement phyto J+30 ») et calcule due/overdue — mais il est en
 * lecture seule. Ces étapes n'existaient donc NULLE PART dans le calendrier de
 * tâches, ni dans le taux de complétion. Un technicien pouvait afficher 100 % de
 * complétion en ayant manqué chaque intervention phénologique de la saison :
 * l'indicateur central du pilotage à distance était structurellement faux.
 *
 * Deux colonnes de liaison suffisent à faire d'une étape une vraie tâche, une
 * seule fois, et à refermer la boucle à la complétion :
 *  - task_assignments.crop_cycle_id        (sur QUEL cycle)
 *  - task_assignments.crop_protocol_item_id (QUELLE étape de l'itinéraire)
 *
 * Et une colonne pour dégonfler le bruit calendaire :
 *  - task_templates.months : un arrosage quotidien généré toute l'année tourne
 *    aussi en pleine saison des pluies. Une tâche sans objet ce jour-là apprend
 *    au technicien à cocher sans faire — elle empoisonne le dénominateur du taux
 *    de complétion, donc la mesure elle-même.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_assignments')) {
            Schema::table('task_assignments', function (Blueprint $table) {
                if (! Schema::hasColumn('task_assignments', 'crop_cycle_id')) {
                    $table->foreignId('crop_cycle_id')->nullable()->after('plot_id')
                        ->constrained('crop_cycles')->nullOnDelete();
                }
                if (! Schema::hasColumn('task_assignments', 'crop_protocol_item_id')) {
                    $table->foreignId('crop_protocol_item_id')->nullable()->after('crop_cycle_id')
                        ->constrained('crop_protocol_items')->nullOnDelete();
                }
            });

            // UNE tâche par (cycle, étape) : c'est cet index qui rend la
            // génération rejouable tous les jours sans dupliquer. Les colonnes
            // étant nullables, les tâches non phénologiques (la majorité) ne
            // sont pas contraintes — MySQL et SQLite admettent plusieurs NULL
            // dans un index unique.
            if (! $this->indexExists('task_assignments', 'task_assignments_cycle_step_unique')) {
                Schema::table('task_assignments', function (Blueprint $table) {
                    $table->unique(['crop_cycle_id', 'crop_protocol_item_id'], 'task_assignments_cycle_step_unique');
                });
            }
        }

        if (Schema::hasTable('task_templates') && ! Schema::hasColumn('task_templates', 'months')) {
            Schema::table('task_templates', function (Blueprint $table) {
                // null = tous les mois (comportement actuel inchangé).
                $table->json('months')->nullable()->after('day_of_month');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_assignments')) {
            if ($this->indexExists('task_assignments', 'task_assignments_cycle_step_unique')) {
                Schema::table('task_assignments', function (Blueprint $table) {
                    $table->dropUnique('task_assignments_cycle_step_unique');
                });
            }
            Schema::table('task_assignments', function (Blueprint $table) {
                foreach (['crop_protocol_item_id', 'crop_cycle_id'] as $column) {
                    if (Schema::hasColumn('task_assignments', $column)) {
                        $table->dropConstrainedForeignId($column);
                    }
                }
            });
        }

        if (Schema::hasColumn('task_templates', 'months')) {
            Schema::table('task_templates', function (Blueprint $table) {
                $table->dropColumn('months');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return Schema::getConnection()
                ->getSchemaBuilder()
                ->getIndexes($table) !== []
                && collect(Schema::getConnection()->getSchemaBuilder()->getIndexes($table))
                    ->contains(fn ($i) => ($i['name'] ?? null) === $index);
        } catch (\Throwable) {
            return false;
        }
    }
};
