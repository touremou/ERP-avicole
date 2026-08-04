<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mill_productions.status` : ENUM incomplet → chaîne.
 *
 * Trouvé en rejouant la suite de tests sur MySQL.
 *
 * L'ENUM d'origine liste Planifié / En cours / Terminé / Échec. Le statut
 * « Annulé » a été ajouté plus tard : le contrôleur l'écrit
 * (MillProductionController::annuler), le modèle lui donne une couleur, la
 * synchro et les listes le filtrent explicitement. Tout le code le connaît
 * sauf la base.
 *
 * Conséquence en production : ANNULER un ordre de production échoue — et
 * l'ordre annulé continue d'occuper sa machine, puisque l'occupation ne se
 * libère que sur « Terminé » ou « Annulé ».
 *
 * On repasse en chaîne plutôt que d'ajouter la valeur : le prochain statut
 * ajouté au code repasserait sinon par le même défaut silencieux.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mill_productions') || ! Schema::hasColumn('mill_productions', 'status')) {
            return;
        }

        Schema::table('mill_productions', function (Blueprint $table) {
            $table->string('status', 20)->default('Planifié')->change();
        });
    }

    public function down(): void
    {
        // Pas de retour à l'ENUM : il rejetterait les ordres « Annulé » déjà
        // enregistrés.
    }
};
