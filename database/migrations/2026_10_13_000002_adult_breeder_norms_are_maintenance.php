<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UN BÉLIER ADULTE N'EST PAS « EN CROISSANCE ».
 *
 * Les normes des reproducteurs mammifères (bélier Djallonké, bouc sahélien,
 * lapin californien) sont une seule ligne de valeurs ADULTES (35 kg, 1,5 kg de
 * ration, 5 L d'eau) — étiquetée « Croissance ». Signalé par l'exploitation :
 * la fiche d'une bande de béliers affichait « S-1 · Croissance ». C'est leur
 * entretien. Seule l'étiquette change : les valeurs restent.
 */
return new class extends Migration
{
    private const SOUCHES = ['Bélier Djallonké', 'Bouc Sahélien', 'Lapin Californien'];

    public function up(): void
    {
        DB::table('production_norms')
            ->where('batch_type', 'reproducteur')
            ->whereIn('model_name', self::SOUCHES)
            ->where('phase_name', 'Croissance')
            ->update(['phase_name' => 'Entretien']);
    }

    public function down(): void
    {
        DB::table('production_norms')
            ->where('batch_type', 'reproducteur')
            ->whereIn('model_name', self::SOUCHES)
            ->where('phase_name', 'Entretien')
            ->update(['phase_name' => 'Croissance']);
    }
};
