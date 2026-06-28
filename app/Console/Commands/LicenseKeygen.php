<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

/**
 * Génère une paire de clés Ed25519 pour le système de licence (FOURNISSEUR).
 *
 * - La clé PUBLIQUE est à placer dans l'instance cliente : LICENSE_PUBLIC_KEY.
 * - La clé PRIVÉE reste CHEZ LE FOURNISSEUR (coffre / gestionnaire de secrets) :
 *   elle sert à émettre les codes (`license:issue`) et ne doit JAMAIS être livrée.
 *
 * À usage interne fournisseur : ne pas exposer cette commande chez le client.
 */
class LicenseKeygen extends Command
{
    protected $signature   = 'license:keygen';
    protected $description = 'Génère une paire de clés Ed25519 pour signer/vérifier les licences (usage fournisseur).';

    public function handle(): int
    {
        $pair = LicenseService::generateKeypair();

        $this->newLine();
        $this->info('Paire de clés de licence générée (Ed25519).');
        $this->newLine();

        $this->line('  CLÉ PUBLIQUE (à poser dans l\'instance cliente, .env) :');
        $this->line('  LICENSE_PUBLIC_KEY=' . $pair['public']);
        $this->newLine();

        $this->warn('  CLÉ PRIVÉE (À CONSERVER CHEZ LE FOURNISSEUR — NE JAMAIS LIVRER) :');
        $this->line('  ' . $pair['private']);
        $this->newLine();

        $this->comment('Émettez ensuite un code avec : php artisan license:issue --private-key=<clé privée> ...');

        return self::SUCCESS;
    }
}
