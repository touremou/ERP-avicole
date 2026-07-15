<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

/**
 * Synchronise la licence avec le serveur du fournisseur (vérification en ligne
 * hybride, opt-in). Sans serveur configuré (LICENSE_SERVER_URL vide), ne fait
 * rien — l'ERP reste 100 % hors-ligne.
 *
 * Planifiée quotidiennement ; tolérante à l'absence de réseau (zone rurale).
 */
class LicenseSync extends Command
{
    protected $signature   = 'license:sync {--force : Ignore l\'intervalle et force la vérification}';
    protected $description = 'Vérifie en ligne l\'état de la licence (révocation / renouvellement à distance).';

    public function handle(LicenseService $licenses): int
    {
        if (! $licenses->onlineCheckConfigured()) {
            $this->line('Vérification en ligne non configurée (mode hors-ligne). Rien à faire.');
            return self::SUCCESS;
        }

        $result = $licenses->syncOnline((bool) $this->option('force'));

        $this->info(match ($result) {
            'revoked' => 'Licence RÉVOQUÉE par le fournisseur — accès bloqué.',
            'renewed' => 'Licence renouvelée à distance.',
            'ok'      => 'Licence vérifiée : valide.',
            default   => 'Aucune synchronisation effectuée (intervalle non écoulé ou serveur injoignable).',
        });

        return self::SUCCESS;
    }
}
