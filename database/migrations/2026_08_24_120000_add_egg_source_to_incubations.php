<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROVENANCE DES ŒUFS MIS À COUVER.
 *
 * `StartIncubationRequest` validait déjà `source_type` (internal/external) et
 * exigeait un lot ou un fournisseur en conséquence — mais rien de tout cela
 * n'était ENREGISTRÉ. La table ne portait aucun champ de provenance.
 *
 * Conséquence : la mise en couvoir ne pouvait pas déstocker, faute de savoir si
 * les œufs venaient du magasin ou d'un fournisseur. Des œufs collectés restaient
 * donc comptés en stock vendable pendant qu'ils étaient à l'incubateur.
 *
 * Depuis #305 c'est devenu opérationnel : une vente déstocke et refuse si le
 * magasin est vide — mais un stock gonflé par les œufs en incubation ne refuse
 * pas, et l'on peut vendre des œufs physiquement dans une machine.
 *
 * `egg_grade` dit QUEL calibre a été prélevé : sans lui, on ne saurait ni le
 * déduire ni le restituer à l'abandon du cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            $table->string('source_type', 10)->default('internal')->after('batch_id');
            $table->string('egg_grade', 10)->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('incubations', fn (Blueprint $t) => $t->dropColumn(['source_type', 'egg_grade']));
    }
};
