<?php

use App\Support\PrivateUpload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Déplace les pièces SENSIBLES déjà téléversées vers le disque privé.
 *
 * Tant qu'elles restent sur le disque « public », elles répondent à /storage/…
 * sans aucun compte : le déploiement exécute `php artisan storage:link`, et le
 * serveur web les sert directement, sans passer par l'application.
 *
 * #248 avait sorti les justificatifs de dépense. Celle-ci traite le reste :
 * photos et CV d'employés, photos d'autopsie, relevés de nettoyage, documents de
 * réception, clichés pris au champ.
 *
 * ON PARCOURT LE DISQUE, PAS LES TABLES. Ces chemins sont éparpillés dans une
 * dizaine de colonnes (employees.photo_path, employees.cv_path,
 * health_incidents.photo_path, cleaning_logs, slaughter_receptions,
 * task_assignments…), et en oublier une laisserait des pièces derrière. Les
 * DOSSIERS, eux, sont la liste exhaustive — et c'est la même liste que celle qui
 * décide de l'accès (PrivateUpload::PRIVATE_PREFIXES) : une seule déclaration,
 * deux usages.
 *
 * PRUDENCE IDENTIQUE À #248 : le chemin en base ne change pas (il est relatif au
 * disque), on COPIE, on RELIT, puis on supprime ; un échec sur un fichier
 * n'interrompt pas les autres, et ce qui n'a pas pu être déplacé reste lisible
 * par l'application (repli) et NOMMÉMENT journalisé.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ancien = Storage::disk(PrivateUpload::LEGACY_DISK);
        $prive  = Storage::disk(PrivateUpload::DISK);

        $deplaces = 0;
        $echecs   = 0;

        foreach (PrivateUpload::PRIVATE_PREFIXES as $prefixe) {
            $dossier = rtrim($prefixe, '/');

            if (! $ancien->exists($dossier)) {
                continue;
            }

            foreach ($ancien->allFiles($dossier) as $chemin) {
                if ($prive->exists($chemin)) {
                    continue;
                }

                try {
                    $prive->put($chemin, $ancien->get($chemin));

                    if ($prive->exists($chemin)) {
                        $ancien->delete($chemin);
                        $deplaces++;
                    } else {
                        $echecs++;
                        Log::warning("Pièce non déplacée (copie absente) : {$chemin}");
                    }
                } catch (\Throwable $e) {
                    $echecs++;
                    Log::warning("Pièce non déplacée : {$chemin} — {$e->getMessage()}");
                }
            }
        }

        Log::info("Téléversements sensibles : {$deplaces} déplacé(s) vers le disque privé, {$echecs} échec(s).");
    }

    /**
     * Pas de retour en arrière automatique : il remettrait des visages, des CV et
     * des documents sur un disque servi en statique.
     */
    public function down(): void
    {
        // Volontairement vide.
    }
};
