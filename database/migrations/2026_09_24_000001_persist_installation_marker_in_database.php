<?php

use App\Support\InstallationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * LE MARQUEUR D'INSTALLATION DESCEND EN BASE.
 *
 * `storage/installed` ne voyage pas avec l'application — le guide de
 * déploiement exclut `storage/` du rsync, délibérément. Il est posé une seule
 * fois, sur l'hôte, à la fin du premier assistant. Tout ce qui reprovisionne
 * `storage/` l'efface, pendant que la base reste pleine : l'assistant se
 * rouvrait alors au premier visiteur venu, qui pouvait REPRENDRE le compte
 * administrateur (cf. App\Support\InstallationState).
 *
 * Cette migration recopie le marqueur EN BASE sur les hôtes qui le portent
 * encore — c'est-à-dire toutes les installations en service au moment de la
 * mise à jour. À partir de là, il survit à tout redéploiement.
 *
 * ─── POURQUOI ELLE NE SE DÉCLENCHE PAS SUR UNE INSTALLATION NEUVE ───
 *
 * Les migrations tournent à l'ÉTAPE 3 de l'assistant, avant la finalisation :
 * le fichier n'existe pas encore, rien n'est écrit, et l'assistant continue
 * normalement. La condition est donc exactement le bon discriminant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! File::exists(InstallationState::fichierMarqueur())) {
            return;
        }

        [$group, $key] = explode('.', InstallationState::CLEF, 2);

        $dejaPose = DB::table('settings')->where('group', $group)->where('key', $key)->exists();

        if ($dejaPose) {
            return;
        }

        // On conserve la DATE du fichier : elle dit quand cette installation a
        // réellement été finalisée, ce qu'un `now()` effacerait.
        $horodatage = trim((string) File::get(InstallationState::fichierMarqueur()))
            ?: now()->toDateTimeString();

        DB::table('settings')->insert([
            'group'      => $group,
            'key'        => $key,
            'value'      => $horodatage,
            'type'       => 'text',
            'label'      => 'Installation finalisée le',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        [$group, $key] = explode('.', InstallationState::CLEF, 2);

        DB::table('settings')->where('group', $group)->where('key', $key)->delete();
    }
};
