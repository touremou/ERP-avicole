<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Console\Command;

/**
 * Génère la paire de clefs VAPID qui autorise ce serveur à pousser des
 * notifications vers les navigateurs.
 *
 * À exécuter UNE FOIS à l'installation :
 *
 *     php artisan push:generate-keys
 *
 * La clef publique est distribuée aux appareils ; la privée signe chaque envoi.
 * Les remplacer INVALIDE tous les abonnements existants — chaque téléphone doit
 * alors réaccepter les notifications. La commande refuse donc d'écraser une paire
 * en place sans --force, et dit combien d'appareils y perdraient leur abonnement.
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'push:generate-keys {--force : Remplacer une paire existante (invalide tous les abonnements)}';

    protected $description = 'Génère la paire de clefs VAPID du push navigateur';

    public function handle(WebPushService $push): int
    {
        if ($push->isConfigured() && ! $this->option('force')) {
            $devices = PushSubscription::count();

            $this->error('Une paire de clefs VAPID existe déjà.');
            $this->line("La remplacer invaliderait {$devices} abonnement(s) : chaque appareil");
            $this->line('devrait réaccepter les notifications.');
            $this->newLine();
            $this->line('Pour le faire malgré tout : php artisan push:generate-keys --force');

            return self::FAILURE;
        }

        if ($this->option('force') && $push->isConfigured()) {
            $devices = PushSubscription::count();

            if ($devices > 0 && ! $this->confirm("{$devices} appareil(s) perdront leur abonnement. Continuer ?", false)) {
                $this->info('Annulé — aucune clef modifiée.');

                return self::SUCCESS;
            }

            PushSubscription::query()->delete();
            $this->warn("{$devices} abonnement(s) supprimé(s) : ils ne fonctionneraient plus.");
        }

        $keys = $push->generateKeys(force: true);

        $this->info('Paire de clefs VAPID générée et enregistrée au paramétrage.');
        $this->newLine();
        $this->line('Clef publique (distribuée aux appareils, non secrète) :');
        $this->line($keys['publicKey']);
        $this->newLine();
        $this->warn('La clef privée est enregistrée en base et ne sera plus affichée.');
        $this->line('Sauvegardez-la si vous prévoyez de migrer de serveur sans');
        $this->line('demander à tout le monde de se réabonner.');

        return self::SUCCESS;
    }
}
