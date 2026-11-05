<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LE VIDE SANITAIRE COMMENCE À LA SORTIE DU DERNIER ANIMAL.
 *
 * Décision de l'exploitation : il démarre quand l'effectif tombe à zéro, plus à
 * la clôture comptable. Les lots déjà SOLDÉS ont vidé leur bâtiment sans le
 * mettre en désinfection : il le passe, daté du passage à « Soldé »
 * (dernière écriture du lot), s'il n'abrite plus aucune bande vivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        $soldes = DB::table('batches')
            ->where('status', 'Soldé')
            ->where('initial_quantity', '>', 0)
            ->when(Schema::hasColumn('batches', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->get(['id', 'building_id', 'updated_at']);

        foreach ($soldes as $lot) {
            if (! $lot->building_id) {
                continue;
            }

            $habite = DB::table('batches')
                ->where('building_id', $lot->building_id)
                ->where('id', '!=', $lot->id)
                ->where('status', 'Actif')
                ->where('current_quantity', '>', 0)
                ->when(Schema::hasColumn('batches', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->exists();

            if ($habite) {
                continue;
            }

            DB::table('buildings')
                ->where('id', $lot->building_id)
                ->where('status', 'Occupé')
                ->update([
                    'status' => 'En désinfection',
                    'disinfection_started_at' => $lot->updated_at,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Constat sur des données : rien à défaire.
    }
};
