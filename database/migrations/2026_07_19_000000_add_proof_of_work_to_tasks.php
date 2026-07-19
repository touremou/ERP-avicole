<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preuve d'exécution (Proof of Work).
 *
 * Valider une tâche était un clic nu (task.complete ne portait que task_id).
 * On introduit une EXIGENCE DE PREUVE par type de tâche :
 *   - `photo`  : photo obligatoire (sac d'aliment vidé, plante malade…) ;
 *   - `valeur` : donnée chiffrée précise (nombre de morts, poids pesé…) ;
 *   - `aucune` : validation simple (par défaut, rétro-compatible).
 *
 * L'exigence vit sur le TEMPLATE (config) et est copiée sur l'ASSIGNATION
 * (dénormalisée → disponible hors-ligne dans le miroir mobile). La preuve
 * captée (photo/valeur) est stockée sur l'assignation à la complétion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $t) {
            $t->string('proof_type', 10)->default('aucune')->after('description'); // aucune | photo | valeur
            $t->string('proof_label')->nullable()->after('proof_type');            // "Nombre de mortalités"
            $t->string('proof_unit', 20)->nullable()->after('proof_label');        // "sujets", "kg"
        });

        Schema::table('task_assignments', function (Blueprint $t) {
            $t->string('proof_type', 10)->default('aucune')->after('description');
            $t->string('proof_label')->nullable()->after('proof_type');
            $t->string('proof_unit', 20)->nullable()->after('proof_label');
            $t->string('proof_photo_path')->nullable()->after('completion_notes');
            $t->decimal('proof_value', 12, 2)->nullable()->after('proof_photo_path');
        });

        // Exigences par défaut sur les templates seedés (les plus sensibles) —
        // l'admin peut ajuster ensuite. Cf. exemples de la spec.
        $rules = [
            ['name' => 'Relevé mortalité',  'proof_type' => 'valeur', 'proof_label' => 'Nombre de mortalités', 'proof_unit' => 'sujets'],
            ['name' => 'Alimentation matin', 'proof_type' => 'photo', 'proof_label' => 'Photo du sac vidé',      'proof_unit' => null],
            ['name' => 'Alimentation midi',  'proof_type' => 'photo', 'proof_label' => 'Photo du sac vidé',      'proof_unit' => null],
            ['name' => 'Alimentation soir',  'proof_type' => 'photo', 'proof_label' => 'Photo du sac vidé',      'proof_unit' => null],
            ['name' => 'Pesée échantillon',  'proof_type' => 'valeur', 'proof_label' => 'Poids moyen',           'proof_unit' => 'kg'],
        ];

        foreach ($rules as $r) {
            DB::table('task_templates')->where('name', $r['name'])->update([
                'proof_type'  => $r['proof_type'],
                'proof_label' => $r['proof_label'],
                'proof_unit'  => $r['proof_unit'],
            ]);
        }

        // Rétro-application aux assignations ENCORE OUVERTES : les tâches déjà
        // générées pour aujourd'hui héritent de l'exigence de leur template
        // (sinon la preuve n'entrerait en vigueur qu'au prochain cycle).
        foreach ($rules as $r) {
            $templateIds = DB::table('task_templates')->where('name', $r['name'])->pluck('id');
            if ($templateIds->isEmpty()) continue;

            DB::table('task_assignments')
                ->whereIn('task_template_id', $templateIds)
                ->whereIn('status', ['a_faire', 'en_cours', 'en_retard'])
                ->update([
                    'proof_type'  => $r['proof_type'],
                    'proof_label' => $r['proof_label'],
                    'proof_unit'  => $r['proof_unit'],
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $t) {
            $t->dropColumn(['proof_type', 'proof_label', 'proof_unit']);
        });
        Schema::table('task_assignments', function (Blueprint $t) {
            $t->dropColumn(['proof_type', 'proof_label', 'proof_unit', 'proof_photo_path', 'proof_value']);
        });
    }
};
