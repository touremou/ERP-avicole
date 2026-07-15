<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Émet un code de licence signé (FOURNISSEUR).
 *
 * Construit le payload (client, plan, validité, quotas) à partir du catalogue
 * de plans (config/license.php) puis le signe avec la clé privée Ed25519. Le
 * code produit est à communiquer au client, qui l'active dans son instance
 * (écran « Prolongez la date de validité »).
 *
 * Exemples :
 *   php artisan license:issue --id=BIOCREST --client="BioCrest" --plan=pro --days=366 --private-key=...
 *   php artisan license:issue --id=FERME01 --plan=entreprise --days=30 --sms=500 --domain=erp.client.com --private-key=...
 */
class LicenseIssue extends Command
{
    protected $signature = 'license:issue
        {--id= : Identifiant client (saisi par le client à l\'activation)}
        {--client= : Nom commercial du client (affichage)}
        {--plan=basic : Plan : basic|pro|entreprise}
        {--days=366 : Durée de validité en jours}
        {--sms= : Quota SMS (sinon valeur du plan)}
        {--modules= : Modules CSV pour surcharger le plan (ex: elevage,commerce ; * = tous)}
        {--domain= : Lie la licence à un domaine (anti-copie ; optionnel)}
        {--private-key= : Clé privée Ed25519 base64 (fournisseur)}';

    protected $description = 'Émet un code de licence signé à communiquer au client (usage fournisseur).';

    public function handle(): int
    {
        $privateKey = (string) $this->option('private-key');
        if ($privateKey === '') {
            $this->error('--private-key est requis (clé privée fournisseur, cf. license:keygen).');
            return self::FAILURE;
        }

        $id = trim((string) $this->option('id'));
        if ($id === '') {
            $this->error('--id est requis (identifiant client).');
            return self::FAILURE;
        }

        $planSlug = (string) $this->option('plan');
        $plans = (array) config('license.plans', []);
        if (! isset($plans[$planSlug])) {
            $this->error("Plan inconnu : {$planSlug}. Plans disponibles : " . implode(', ', array_keys($plans)));
            return self::FAILURE;
        }
        $plan = $plans[$planSlug];

        $days = max(1, (int) $this->option('days'));

        // Modules : surcharge CSV éventuelle, sinon ceux du plan.
        $modules = $plan['modules'] ?? [];
        if ($csv = $this->option('modules')) {
            $modules = array_values(array_filter(array_map('trim', explode(',', $csv))));
        }

        $now = now();
        $payload = [
            'v'         => 1,
            'id'        => $id,
            'client'    => $this->option('client') ?: $id,
            'plan'      => $planSlug,
            'modules'   => $modules,
            'max_users' => (int) ($plan['max_users'] ?? 0),
            'max_farms' => (int) ($plan['max_farms'] ?? 0),
            'sms_quota' => $this->option('sms') !== null ? (int) $this->option('sms') : (int) ($plan['sms_quota'] ?? 0),
            'iat'       => $now->getTimestamp(),
            'nbf'       => $now->getTimestamp(),
            'exp'       => $now->copy()->addDays($days)->getTimestamp(),
        ];

        if ($domain = $this->option('domain')) {
            $payload['fp'] = hash('sha256', $domain);
        }

        try {
            $code = LicenseService::sign($payload, $privateKey);
        } catch (\Throwable $e) {
            $this->error('Échec de signature : ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Licence émise — client « {$payload['client']} » / plan {$planSlug} / {$days} jours");
        $this->line('  Identifiant   : ' . $id);
        $this->line('  Expire le     : ' . Carbon::createFromTimestamp($payload['exp'])->toDateString());
        $this->line('  Quota SMS     : ' . $payload['sms_quota']);
        $this->newLine();
        $this->line('CODE DE VALIDITÉ (à transmettre au client) :');
        $this->line($code);
        $this->newLine();

        return self::SUCCESS;
    }
}
