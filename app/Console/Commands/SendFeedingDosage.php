<?php

namespace App\Console\Commands;

use App\Services\NotificationHub;
use Illuminate\Console\Command;

class SendFeedingDosage extends Command
{
    protected $signature   = 'avismart:feeding-dosage';
    protected $description = 'Envoie le dosage d\'aliment recommandé par bâtiment via WhatsApp';

    public function handle(NotificationHub $hub): int
    {
        $this->info('🌾 Calcul des dosages recommandés...');

        $sent = $hub->sendFeedingDosage();

        $this->info("✅ Dosage envoyé à {$sent} destinataire(s).");

        return self::SUCCESS;
    }
}
