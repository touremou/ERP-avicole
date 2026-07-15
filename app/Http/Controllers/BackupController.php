<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Gestion des sauvegardes (spatie/laravel-backup) côté administrateur :
 * lister, déclencher manuellement, télécharger. Réservé à l'admin — un dump de
 * base de données est une donnée hautement sensible (jamais exposé publiquement).
 */
class BackupController extends Controller
{
    private function disk()
    {
        return Storage::disk('backups');
    }

    public function index()
    {
        if (Gate::denies('admin.S')) {
            return redirect()->route('dashboard')->with('error', 'Accès réservé à l\'administrateur.');
        }

        $disk = $this->disk();

        $backups = collect($disk->allFiles())
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->map(fn ($f) => [
                'path'     => $f,
                'name'     => basename($f),
                'size'     => $disk->size($f),
                'date'     => $disk->lastModified($f),
            ])
            ->sortByDesc('date')
            ->values();

        return view('backups.index', compact('backups'));
    }

    public function run()
    {
        if (Gate::denies('admin.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        // --only-db : sauvegarde rapide déclenchable à la demande (la sauvegarde
        // complète DB+fichiers tourne via le cron quotidien).
        Artisan::queue('backup:run');

        return back()->with('success', 'Sauvegarde lancée. Elle apparaîtra dans la liste dans quelques instants.');
    }

    public function download(string $name)
    {
        if (Gate::denies('admin.S')) {
            abort(403);
        }

        // Anti path-traversal : on ne sert qu'un .zip présent sur le disque backups.
        abort_if(str_contains($name, '..') || str_contains($name, '/'), 404);

        $disk = $this->disk();
        $match = collect($disk->allFiles())->first(fn ($f) => basename($f) === $name && str_ends_with($f, '.zip'));

        abort_unless($match, 404);

        return $disk->download($match);
    }
}
