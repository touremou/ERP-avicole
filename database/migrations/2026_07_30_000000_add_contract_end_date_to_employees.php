<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN DE CONTRAT À DURÉE DÉTERMINÉE — la date qui manquait.
 *
 * `contract_type` acceptait CDD et Journalier sans jamais demander de terme :
 * un contrat à durée déterminée sans durée. Conséquence pratique : rien ne
 * signalait l'échéance, donc rien ne déclenchait la décision — prolonger, ou
 * émettre un préavis. Un CDD qui court au-delà de son terme sans acte se
 * requalifie ; c'est un risque juridique, pas une coquetterie de formulaire.
 *
 * On ajoute donc :
 *   - contract_end_date  le terme (obligatoire sur CDD/Journalier, interdit
 *                        sur CDI — un CDI n'a pas de fin par nature) ;
 *   - notice_given_at    la date d'émission du préavis, pour prouver qu'il l'a
 *                        été et quand ;
 *   - employee_contract_events  la TRACE des décisions. Écraser
 *                        contract_end_date à chaque prolongation effacerait
 *                        l'historique : on ne saurait plus qu'un CDD a été
 *                        prolongé trois fois, ce qui est précisément ce qu'un
 *                        contrôle regarde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('contract_end_date')->nullable()->after('contract_type');
            $table->date('notice_given_at')->nullable()->after('contract_end_date');
        });

        Schema::create('employee_contract_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // prolongation : le terme est repoussé ; preavis : la fin est
            // notifiée ; fin : le contrat est arrivé à son terme sans suite.
            $table->enum('type', ['prolongation', 'preavis', 'fin']);
            $table->date('decided_on');
            $table->date('previous_end_date')->nullable();
            $table->date('new_end_date')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'decided_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contract_events');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['contract_end_date', 'notice_given_at']);
        });
    }
};
