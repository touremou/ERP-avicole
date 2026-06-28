<?php

namespace LicenseServer;

use RuntimeException;

/**
 * LicenseAuthority — signature/vérification des licences (CÔTÉ FOURNISSEUR).
 *
 * Réplique EXACTEMENT le format de jeton de l'ERP client
 * (App\Services\LicenseService) pour garantir l'interopérabilité :
 *
 *     base64url(payload_json) . "." . base64url(signature_Ed25519)
 *
 * Cette classe détient/charge la CLÉ PRIVÉE : elle ne doit JAMAIS être déployée
 * chez un client. Seule la clé publique correspondante est embarquée dans l'ERP.
 */
final class LicenseAuthority
{
    public function __construct(private string $privateKeyBase64) {}

    /** Génère une paire de clés Ed25519 (base64). */
    public static function generateKeypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public'  => base64_encode(sodium_crypto_sign_publickey($pair)),
            'private' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /** Clé publique (base64) dérivée de la clé privée — à poser dans l'ERP. */
    public function publicKey(): string
    {
        $secret = $this->decodeSecret();

        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    }

    /**
     * Signe un payload et retourne le code de licence.
     *
     * @param array $payload  Doit contenir au moins id, exp.
     */
    public function sign(array $payload): string
    {
        $secret = $this->decodeSecret();
        $json   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig    = sodium_crypto_sign_detached($json, $secret);

        return self::b64uEncode($json) . '.' . self::b64uEncode($sig);
    }

    /** Vérifie un code et retourne le payload (utile pour /check et selftest). */
    public function verify(string $code): array
    {
        $public = sodium_crypto_sign_publickey_from_secretkey($this->decodeSecret());

        $parts = explode('.', trim($code));
        if (count($parts) !== 2) {
            throw new RuntimeException('Format de code invalide.');
        }

        $json = self::b64uDecode($parts[0]);
        $sig  = self::b64uDecode($parts[1]);
        if ($json === false || $sig === false) {
            throw new RuntimeException('Code illisible.');
        }

        if (! sodium_crypto_sign_verify_detached($sig, $json, $public)) {
            throw new RuntimeException('Signature invalide.');
        }

        $payload = json_decode($json, true);
        if (! is_array($payload) || empty($payload['id']) || empty($payload['exp'])) {
            throw new RuntimeException('Payload incomplet.');
        }

        return $payload;
    }

    /**
     * Construit un payload normalisé à partir des paramètres commerciaux.
     *
     * @param array $plans  Catalogue des plans (cf. config.php).
     */
    public function buildPayload(array $plans, array $opts): array
    {
        $planSlug = $opts['plan'] ?? 'basic';
        if (! isset($plans[$planSlug])) {
            throw new RuntimeException("Plan inconnu : {$planSlug}");
        }
        $plan = $plans[$planSlug];

        $now  = time();
        $days = max(1, (int) ($opts['days'] ?? 366));

        $payload = [
            'v'         => 1,
            'id'        => $opts['id'],
            'client'    => $opts['client'] ?? $opts['id'],
            'plan'      => $planSlug,
            'modules'   => $opts['modules'] ?? ($plan['modules'] ?? []),
            'max_users' => (int) ($plan['max_users'] ?? 0),
            'max_farms' => (int) ($plan['max_farms'] ?? 0),
            'sms_quota' => isset($opts['sms']) ? (int) $opts['sms'] : (int) ($plan['sms_quota'] ?? 0),
            'iat'       => $now,
            'nbf'       => $now,
            'exp'       => $now + $days * 86400,
        ];

        if (! empty($opts['domain'])) {
            $payload['fp'] = hash('sha256', $opts['domain']);
        }

        return $payload;
    }

    private function decodeSecret(): string
    {
        $secret = base64_decode($this->privateKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Clé privée Ed25519 invalide.');
        }

        return $secret;
    }

    private static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
