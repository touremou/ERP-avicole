<?php

use App\Support\ReceiptStorage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Déplace les justificatifs de dépense DÉJÀ DÉPOSÉS vers le disque privé.
 *
 * Tant qu'ils restent sur le disque « public », ils répondent à /storage/… sans
 * aucun compte : le déploiement exécute `php artisan storage:link`, et le
 * serveur web les sert directement, sans passer par l'application ni par le
 * droit `depenses.L`.
 *
 * PRUDENCE DÉLIBÉRÉE. Le chemin enregistré en base ne change PAS (il est relatif
 * au disque) : rien à réécrire côté données, et la lecture sait regarder les
 * deux emplacements. On COPIE puis on supprime, et un échec sur un fichier
 * n'interrompt pas les autres — sur un hébergement mutualisé, un quota ou un
 * droit peut faire échouer une écriture, et perdre une pièce comptable au
 * milieu d'un déploiement serait pire que le défaut corrigé.
 *
 * Ce qui n'a pas pu être déplacé reste lisible par l'application (repli) et
 * NOMMÉMENT journalisé, pour être repris à la main.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        $ancien = Storage::disk(ReceiptStorage::LEGACY_DISK);
        $prive  = Storage::disk(ReceiptStorage::DISK);

        $deplaces = 0;
        $echecs   = 0;

        DB::table('expenses')
            ->whereNotNull('justificatif_path')
            ->orderBy('id')
            ->pluck('justificatif_path')
            ->unique()
            ->each(function ($chemin) use ($ancien, $prive, &$deplaces, &$echecs) {
                if (! $ancien->exists($chemin) || $prive->exists($chemin)) {
                    return;
                }

                try {
                    $prive->put($chemin, $ancien->get($chemin));

                    // On ne supprime QU'APRÈS avoir vérifié la copie : sans cette
                    // relecture, une écriture silencieusement tronquée
                    // détruirait l'original.
                    if ($prive->exists($chemin)) {
                        $ancien->delete($chemin);
                        $deplaces++;
                    } else {
                        $echecs++;
                        Log::warning("Justificatif non déplacé (copie absente) : {$chemin}");
                    }
                } catch (\Throwable $e) {
                    $echecs++;
                    Log::warning("Justificatif non déplacé : {$chemin} — {$e->getMessage()}");
                }
            });

        Log::info("Justificatifs de dépense : {$deplaces} déplacé(s) vers le disque privé, {$echecs} échec(s).");
    }

    /**
     * Pas de retour en arrière automatique : il remettrait des pièces
     * comptables sur un disque servi en statique. Le repli de lecture rend de
     * toute façon l'application compatible avec les deux emplacements.
     */
    public function down(): void
    {
        // Volontairement vide.
    }
};
