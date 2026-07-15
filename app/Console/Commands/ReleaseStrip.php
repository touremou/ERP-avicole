<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Durcissement « light » GRATUIT d'une COPIE de release (sans encodeur payant).
 *
 * Réécrit chaque fichier PHP du code applicatif via php_strip_whitespace() :
 * suppression de TOUS les commentaires et de la mise en forme, sans altérer la
 * sémantique (basé sur le tokenizer de PHP). Le code reste exécutable mais
 * devient dense et illisible — protection raisonnable en attendant un encodeur
 * (ionCube / SourceGuardian).
 *
 * SÉCURITÉ : refuse de s'exécuter sur l'arborescence de travail (base_path) —
 * on opère TOUJOURS sur une copie de distribution dédiée, jamais sur les
 * sources versionnées.
 *
 * Usage : php artisan release:strip /chemin/vers/copie-de-release
 */
class ReleaseStrip extends Command
{
    protected $signature   = 'release:strip {path : Répertoire de la COPIE de release à durcir}';
    protected $description = 'Retire commentaires et mise en forme du PHP applicatif d\'une copie de release (protection gratuite, sans encodeur).';

    /** Sous-dossiers de code propriétaire à durcir (jamais vendor/). */
    private const TARGET_DIRS = ['app', 'config', 'routes', 'database', 'bootstrap'];

    public function handle(): int
    {
        $path = rtrim((string) $this->argument('path'), '/');

        if ($path === '' || ! is_dir($path)) {
            $this->error("Répertoire introuvable : {$path}");
            return self::FAILURE;
        }

        $real = realpath($path);
        if ($real === false) {
            $this->error('Chemin invalide.');
            return self::FAILURE;
        }

        // Garde-fou : ne JAMAIS durcir les sources de travail.
        if ($real === realpath(base_path())) {
            $this->error('Refus : ce répertoire est l\'arborescence de travail. Faites une COPIE de release d\'abord.');
            return self::FAILURE;
        }

        $dirs = array_filter(
            array_map(fn ($d) => "{$real}/{$d}", self::TARGET_DIRS),
            'is_dir'
        );

        if (empty($dirs)) {
            $this->error('Aucun dossier applicatif (app/config/routes/database/bootstrap) trouvé dans la copie.');
            return self::FAILURE;
        }

        $count = 0;
        $finder = (new Finder())->files()->name('*.php')->in($dirs);

        foreach ($finder as $file) {
            $stripped = php_strip_whitespace($file->getRealPath());
            if ($stripped !== '') {
                file_put_contents($file->getRealPath(), $stripped);
                $count++;
            }
        }

        $this->info("Durcissement terminé : {$count} fichier(s) PHP dépouillé(s) de leurs commentaires et mise en forme.");
        $this->comment('Rappel : protection « light ». La barrière commerciale reste la licence signée. Passez à ionCube/SourceGuardian pour un encodage fort.');

        return self::SUCCESS;
    }
}
