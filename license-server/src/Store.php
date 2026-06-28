<?php

namespace LicenseServer;

use PDO;

/**
 * Store — persistance SQLite (PDO) du registre des licences émises.
 *
 * Une ligne par ÉMISSION (l'historique est conservé). Pour un identifiant
 * client donné, la licence « courante » est la dernière émise non révoquée ;
 * un renouvellement = nouvelle ligne, ce qui rend la précédente « superseded ».
 */
final class Store
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS licenses (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                identifiant  TEXT NOT NULL,
                client_name  TEXT,
                plan         TEXT NOT NULL,
                modules      TEXT,
                max_users    INTEGER DEFAULT 0,
                max_farms    INTEGER DEFAULT 0,
                sms_quota    INTEGER DEFAULT 0,
                domain       TEXT,
                token        TEXT NOT NULL,
                issued_at    INTEGER NOT NULL,
                expires_at   INTEGER NOT NULL,
                revoked      INTEGER DEFAULT 0,
                revoked_at   INTEGER,
                created_at   INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_licenses_ident ON licenses (identifiant);
        SQL);
    }

    /** Enregistre une émission/renouvellement. */
    public function record(array $payload, string $token): int
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO licenses
                (identifiant, client_name, plan, modules, max_users, max_farms, sms_quota, domain, token, issued_at, expires_at, created_at)
            VALUES
                (:identifiant, :client_name, :plan, :modules, :max_users, :max_farms, :sms_quota, :domain, :token, :issued_at, :expires_at, :created_at)
        SQL);

        $stmt->execute([
            'identifiant' => $payload['id'],
            'client_name' => $payload['client'] ?? $payload['id'],
            'plan'        => $payload['plan'] ?? 'basic',
            'modules'     => json_encode($payload['modules'] ?? [], JSON_UNESCAPED_UNICODE),
            'max_users'   => (int) ($payload['max_users'] ?? 0),
            'max_farms'   => (int) ($payload['max_farms'] ?? 0),
            'sms_quota'   => (int) ($payload['sms_quota'] ?? 0),
            'domain'      => $payload['fp'] ?? null,
            'token'       => $token,
            'issued_at'   => (int) ($payload['iat'] ?? time()),
            'expires_at'  => (int) $payload['exp'],
            'created_at'  => time(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Dernière licence émise pour un identifiant (révoquée ou non). */
    public function latestFor(string $identifiant): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE identifiant = :id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['id' => $identifiant]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Révoque TOUTES les licences d'un identifiant. Retourne le nb de lignes. */
    public function revoke(string $identifiant): int
    {
        $stmt = $this->pdo->prepare('UPDATE licenses SET revoked = 1, revoked_at = :ts WHERE identifiant = :id AND revoked = 0');
        $stmt->execute(['ts' => time(), 'id' => $identifiant]);

        return $stmt->rowCount();
    }

    /** Liste toutes les licences (la plus récente d'abord). */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM licenses ORDER BY id DESC')->fetchAll();
    }
}
