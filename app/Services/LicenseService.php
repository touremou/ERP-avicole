<?php

namespace App\Services;

use App\Models\License;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * LicenseService — cœur du système d'abonnement (monétisation).
 *
 * Une licence est un jeton signé Ed25519 émis par le fournisseur (clé privée,
 * jamais livrée) et vérifié localement par l'instance cliente (clé publique
 * embarquée) — AUCUN appel réseau. Format du code :
 *
 *     base64url(payload_json) . "." . base64url(signature)
 *
 * Le payload porte l'identité du client, le plan, les modules déverrouillés,
 * les limites (utilisateurs / fermes / SMS) et les bornes de validité. Le jeton
 * signé fait foi : modifier le catalogue de plans ne dégrade pas une licence
 * déjà vendue.
 */
class LicenseService
{
    public const STATUS_NONE    = 'none';     // aucune licence activée
    public const STATUS_ACTIVE  = 'active';   // valide
    public const STATUS_GRACE   = 'grace';    // expirée mais dans la période de grâce
    public const STATUS_EXPIRED = 'expired';  // expirée, hors grâce → blocage

    /** Clé de cache de l'horodatage « dernier vu » (anti-recul d'horloge). */
    private const CLOCK_KEY = 'license.last_seen_ts';

    // ─────────────────────────────────────────────────────────────
    // ÉTAT GLOBAL
    // ─────────────────────────────────────────────────────────────

    /**
     * Le système de licence est-il ARMÉ ? (clé publique posée + enforcement on).
     * Sinon l'application reste en mode ouvert (aucune restriction).
     */
    public function isEnabled(): bool
    {
        return $this->publicKey() !== '' && (bool) config('license.enforce', true);
    }

    /** Licence active de l'instance (la plus récemment activée). */
    public function current(): ?License
    {
        return License::query()->latest('activated_at')->latest('id')->first();
    }

    /**
     * Statut courant, en tenant compte de la période de grâce et d'un éventuel
     * recul d'horloge (considéré comme expiré par sécurité).
     */
    public function status(): string
    {
        $license = $this->current();
        if (! $license) {
            return self::STATUS_NONE;
        }

        $now = $this->trustedNow();
        $expires = $license->expires_at;

        if ($now->lte($expires)) {
            return self::STATUS_ACTIVE;
        }

        $graceEnd = $expires->copy()->addDays((int) config('license.grace_days', 7));

        return $now->lte($graceEnd) ? self::STATUS_GRACE : self::STATUS_EXPIRED;
    }

    /** L'accès doit-il être bloqué ? (système armé ET statut expiré hors grâce). */
    public function shouldBlock(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return in_array($this->status(), [self::STATUS_NONE, self::STATUS_EXPIRED], true);
    }

    // ─────────────────────────────────────────────────────────────
    // VÉRIFICATION & ACTIVATION
    // ─────────────────────────────────────────────────────────────

    /**
     * Vérifie un code de licence et retourne son payload validé.
     *
     * @throws RuntimeException si le format, la signature ou les bornes sont invalides.
     */
    public function verify(string $code): array
    {
        $publicKey = $this->publicKey();
        if ($publicKey === '') {
            throw new RuntimeException("Aucune clé publique de licence n'est configurée sur cette instance.");
        }

        $parts = explode('.', trim($code));
        if (count($parts) !== 2) {
            throw new RuntimeException('Format de code invalide.');
        }

        $payloadRaw = self::b64uDecode($parts[0]);
        $signature  = self::b64uDecode($parts[1]);

        if ($payloadRaw === false || $signature === false) {
            throw new RuntimeException('Code de licence illisible.');
        }

        $ok = sodium_crypto_sign_verify_detached($signature, $payloadRaw, $publicKey);
        if (! $ok) {
            throw new RuntimeException('Signature de licence invalide (code falsifié ou non émis par le fournisseur).');
        }

        $payload = json_decode($payloadRaw, true);
        if (! is_array($payload) || empty($payload['id']) || empty($payload['exp'])) {
            throw new RuntimeException('Contenu de licence incomplet.');
        }

        return $payload;
    }

    /**
     * Active une licence pour cette instance après vérification.
     *
     * @param  string  $identifiant  Identifiant client saisi (doit correspondre au payload).
     * @param  string  $code         Code de validité (jeton signé).
     * @throws RuntimeException
     */
    public function activate(string $identifiant, string $code): License
    {
        $payload = $this->verify($code);

        if (! hash_equals((string) $payload['id'], trim($identifiant))) {
            throw new RuntimeException("L'identifiant saisi ne correspond pas à ce code de validité.");
        }

        // Liaison optionnelle au domaine : si le jeton fixe une empreinte, elle
        // doit correspondre à l'hôte courant (anti-copie vers une autre instance).
        if (! empty($payload['fp']) && ! hash_equals((string) $payload['fp'], $this->fingerprint())) {
            throw new RuntimeException('Ce code est lié à un autre domaine que celui de cette instance.');
        }

        $license = License::create([
            'identifiant'  => (string) $payload['id'],
            'client_name'  => $payload['client'] ?? null,
            'plan'         => $payload['plan'] ?? 'basic',
            'modules'      => $payload['modules'] ?? [],
            'max_users'    => (int) ($payload['max_users'] ?? 0),
            'max_farms'    => (int) ($payload['max_farms'] ?? 0),
            'sms_quota'    => (int) ($payload['sms_quota'] ?? 0),
            'sms_used'     => 0,
            'fingerprint'  => $payload['fp'] ?? null,
            'issued_at'    => isset($payload['iat']) ? Carbon::createFromTimestamp($payload['iat']) : now(),
            'starts_at'    => isset($payload['nbf']) ? Carbon::createFromTimestamp($payload['nbf']) : now(),
            'expires_at'   => Carbon::createFromTimestamp($payload['exp']),
            'activated_at' => now(),
            'last_seen_at' => now(),
            'token'        => $code,
        ]);

        $this->touchClock();

        return $license;
    }

    // ─────────────────────────────────────────────────────────────
    // QUOTA SMS
    // ─────────────────────────────────────────────────────────────

    /** SMS restants sur le quota de la licence (PHP_INT_MAX si système inactif). */
    public function smsRemaining(): int
    {
        $license = $this->current();
        if (! $this->isEnabled() || ! $license) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $license->sms_quota - (int) $license->sms_used);
    }

    /**
     * Consomme $n SMS du quota. Retourne false (sans consommer) si le quota est
     * épuisé. Toujours true quand le système de licence est inactif.
     */
    public function consumeSms(int $n = 1): bool
    {
        $license = $this->current();
        if (! $this->isEnabled() || ! $license) {
            return true;
        }

        if ($this->smsRemaining() < $n) {
            return false;
        }

        $license->increment('sms_used', $n);

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // MODULES
    // ─────────────────────────────────────────────────────────────

    /** Le module $slug est-il déverrouillé par la licence courante ? */
    public function allowsModule(string $slug): bool
    {
        if (! $this->isEnabled()) {
            return true; // mode ouvert
        }

        $license = $this->current();
        if (! $license) {
            return false;
        }

        $modules = (array) $license->modules;

        return in_array('*', $modules, true) || in_array($slug, $modules, true);
    }

    // ─────────────────────────────────────────────────────────────
    // HORLOGE DE CONFIANCE (anti-recul)
    // ─────────────────────────────────────────────────────────────

    /**
     * « Maintenant » robuste : si l'horloge système a reculé sous le dernier
     * instant observé (tentative de fraude par changement de date), on retient
     * le dernier instant connu — la licence ne « rajeunit » jamais.
     */
    public function trustedNow(): Carbon
    {
        $seen = (int) Cache::get(self::CLOCK_KEY, 0);
        $now  = now();

        if ($seen > $now->getTimestamp()) {
            return Carbon::createFromTimestamp($seen);
        }

        return $now;
    }

    /** Mémorise l'instant courant comme borne basse anti-recul. */
    public function touchClock(): void
    {
        $now = now()->getTimestamp();
        if ($now > (int) Cache::get(self::CLOCK_KEY, 0)) {
            Cache::forever(self::CLOCK_KEY, $now);
        }

        if ($license = $this->current()) {
            $license->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // OUTILS CRYPTO (partagés avec la commande d'émission fournisseur)
    // ─────────────────────────────────────────────────────────────

    /**
     * Signe un payload avec une clé privée Ed25519 (côté fournisseur) et
     * retourne le code de licence prêt à être communiqué au client.
     */
    public static function sign(array $payload, string $privateKeyBase64): string
    {
        $secret = base64_decode($privateKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Clé privée Ed25519 invalide.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig  = sodium_crypto_sign_detached($json, $secret);

        return self::b64uEncode($json) . '.' . self::b64uEncode($sig);
    }

    /** Génère une paire de clés Ed25519 (base64) : ['public' => ..., 'private' => ...]. */
    public static function generateKeypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public'  => base64_encode(sodium_crypto_sign_publickey($pair)),
            'private' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // INTERNES
    // ─────────────────────────────────────────────────────────────

    private function publicKey(): string
    {
        $raw = (string) config('license.public_key', '');
        if ($raw === '') {
            return '';
        }

        $decoded = base64_decode($raw, true);

        return ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $decoded : '';
    }

    /** Empreinte de l'instance (hôte applicatif) pour la liaison optionnelle. */
    private function fingerprint(): string
    {
        return hash('sha256', (string) parse_url((string) config('app.url'), PHP_URL_HOST));
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
