<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

/**
 * Sert les fichiers du disque "public" (logos, photos d'agents, logos
 * fournisseurs…) directement via PHP, sans dépendre du lien symbolique
 * public/storage. Cela garantit l'affichage des images sur tous les
 * environnements (y compris quand `php artisan storage:link` n'a pas été lancé).
 */
class MediaController extends Controller
{
    public function show(string $path)
    {
        // Anti path-traversal : pas de remontée de répertoire.
        abort_if(str_contains($path, '..'), 404);

        $disk = Storage::disk('public');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
