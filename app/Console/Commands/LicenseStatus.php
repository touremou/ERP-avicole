<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

/**
 * État de la licence de CETTE instance — lecture seule, rien n'est écrit.
 *
 * Sert au contrôle d'après déploiement (scripts/deploy/deploy-instance.sh) :
 * une mise à jour ne doit JAMAIS toucher au « certificat » du client (la clé
 * publique LICENSE_PUBLIC_KEY, dans son .env) ni à son « token » (le code de
 * licence activé, en base). La commande affiche l'empreinte de la clé — pas la
 * clé — et l'état du code, pour comparer avant / après.
 *
 * Code de sortie : 0 si l'instance est utilisable, 1 si la licence bloque
 * l'accès (système armé, et licence absente ou expirée hors grâce).
 */
class LicenseStatus extends Command
{
    protected $signature   = 'license:status';
    protected $description = 'Affiche l\'état de la licence de l\'instance (lecture seule).';

    public function handle(LicenseService $licenses): int
    {
        $cle     = (string) config('license.public_key', '');
        $licence = $licenses->current();
        $hote    = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->table(['Élément', 'Valeur'], [
            ['Domaine de l\'instance (APP_URL)', $hote ?: '—'],
            ['Certificat (clé publique)', $cle === '' ? 'ABSENT — licence désactivée (mode ouvert)' : 'présent · empreinte ' . substr(hash('sha256', $cle), 0, 16)],
            ['Système armé', $licenses->isEnabled() ? 'oui' : 'non'],
            ['Token (code activé)', $licence ? $licence->identifiant . ' — ' . ($licence->client_name ?? '') : 'aucun'],
            ['Plan', $licence?->plan ?? '—'],
            ['Échéance', $licence?->expires_at?->toDateString() ?? '—'],
            ['Statut', $licenses->status()],
        ]);

        if ($licenses->shouldBlock()) {
            $this->error('La licence BLOQUE l\'accès : activez un code valide (Administration › Licence).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
