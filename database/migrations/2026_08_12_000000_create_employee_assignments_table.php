<?php

use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AFFECTATION D'UN AGENT À UN SITE — une relation DATÉE, plus une colonne.
 *
 * Jusqu'ici, « qui travaille ici » se déduisait de deux faits sans rapport :
 * `employees.farm_id` (où vit le dossier) et l'accès du COMPTE à une autre ferme
 * (`farm_user`). Le « prêt » n'avait donc jamais été décidé — c'était un effet de
 * bord. D'où sa fuite dans une dizaine d'écrans, chacun redécouvrant la règle à
 * sa façon : sélecteur vide ici, 404 là, garde-fou sauté ailleurs.
 *
 * Une affectation dit explicitement QUI travaille OÙ, DEPUIS QUAND et JUSQU'À
 * QUAND. Mutation et mise à disposition cessent d'être deux mécanismes :
 *
 *   • MUTATION            — l'affectation en cours est close, une nouvelle
 *                           s'ouvre sans terme. Le dossier suit (employees
 *                           .farm_id), donc la paie aussi ;
 *   • MISE À DISPOSITION  — une seconde affectation, BORNÉE, s'ajoute. Le
 *                           dossier ne bouge pas : l'agent reste payé par son
 *                           site d'origine.
 *
 * Ce que la date apporte, et qu'une colonne ne pouvait pas : l'HISTORIQUE. Une
 * paie de mois à cheval sait qu'il était à Kindia du 1er au 15 et à Kérouané
 * ensuite — donc le coût peut un jour être réparti entre les sites. Avec une
 * colonne, la question n'avait pas de réponse.
 *
 * PAS de portée par ferme (BelongsToFarm) sur cette table : c'est elle qui
 * DÉFINIT l'appartenance à une ferme. La filtrer par ferme serait circulaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('farm_id')->constrained();

            // « mutation » = rattachement principal, sans terme.
            // « mise_a_disposition » = prêt, borné dans le temps.
            $table->string('type', 30)->default('mutation');

            $table->date('start_date');
            $table->date('end_date')->nullable();   // null = toujours en cours

            $table->string('reason', 255)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // « Qui travaille sur ce site à cette date » est LA question posée par
            // tous les sélecteurs : elle doit se répondre sans balayer la table.
            $table->index(['farm_id', 'start_date', 'end_date']);
            $table->index(['employee_id', 'start_date']);
        });

        $this->backfill();
    }

    /**
     * REPRISE — rendre explicite ce qui était implicite, SANS rien changer à qui
     * voit quoi aujourd'hui.
     *
     * On reproduit exactement l'ensemble que produisait l'ancienne règle :
     *
     *   1. le rattachement au dossier (employees.farm_id) → une affectation
     *      principale, ouverte, datée de l'embauche ;
     *   2. les fermes auxquelles le COMPTE de l'agent avait accès → autant de
     *      mises à disposition ouvertes.
     *
     * Le point 2 mérite d'être dit au promoteur : ces mises à disposition n'ont
     * jamais été DÉCIDÉES, elles résultaient d'un droit d'accès donné au compte.
     * Elles sont donc marquées comme reprises, pour qu'il puisse clore celles qui
     * ne correspondent à rien. On ne les invente pas : on les révèle.
     */
    private function backfill(): void
    {
        $employees = DB::table('employees')
            ->whereNull('deleted_at')
            ->get(['id', 'farm_id', 'user_id', 'hire_date', 'created_at']);

        $rows = [];

        foreach ($employees as $employee) {
            if (! $employee->farm_id) {
                continue;   // dossier sans site : rien à reprendre
            }

            // La date d'embauche fait foi ; à défaut, la création du dossier.
            // On n'invente pas une date : sans l'une ni l'autre, on prend la plus
            // ancienne possible plutôt que « aujourd'hui », qui rendrait l'agent
            // non affecté pour tout le passé.
            $start = $employee->hire_date ?: ($employee->created_at ?: '2000-01-01');

            $rows[] = [
                'employee_id' => $employee->id,
                'farm_id'     => $employee->farm_id,
                'type'        => 'mutation',
                'start_date'  => substr((string) $start, 0, 10),
                'end_date'    => null,
                'reason'      => 'Reprise : rattachement au dossier',
                'decided_by'  => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            if (! $employee->user_id) {
                continue;
            }

            $otherFarms = DB::table('farm_user')
                ->where('user_id', $employee->user_id)
                ->where('farm_id', '!=', $employee->farm_id)
                ->pluck('farm_id');

            foreach ($otherFarms as $farmId) {
                $rows[] = [
                    'employee_id' => $employee->id,
                    'farm_id'     => $farmId,
                    'type'        => 'mise_a_disposition',
                    'start_date'  => substr((string) $start, 0, 10),
                    'end_date'    => null,
                    'reason'      => 'Reprise : accès du compte à ce site (à confirmer)',
                    'decided_by'  => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('employee_assignments')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assignments');
    }
};
