<?php

/**
 * Front controller HTTP du serveur de licence.
 *
 *   POST /check  → contrat de vérification en ligne hybride consommé par l'ERP
 *                  (App\Services\LicenseService::syncOnline()).
 *   GET  /       → tableau de bord minimal (liste des licences émises).
 *
 * Démarrage local : php -S 127.0.0.1:8989 -t public
 */

use LicenseServer\LicenseAuthority;
use LicenseServer\Store;

$config = require __DIR__ . '/../bootstrap.php';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Réponse JSON. */
function json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── POST /check : contrat de vérification ───
if ($path === '/check' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;
    $identifiant = trim((string) ($body['identifiant'] ?? ''));

    if ($identifiant === '') {
        json(['status' => 'ok', 'message' => 'identifiant manquant'], 400);
    }

    $store   = new Store($config['db_path']);
    $current = $store->latestFor($identifiant);

    // Identifiant inconnu : on ne révoque pas (l'ERP garde son jeton hors-ligne).
    if (! $current) {
        json(['status' => 'ok']);
    }

    if ((int) $current['revoked'] === 1) {
        json(['status' => 'revoked']);
    }

    // Le client présente un jeton plus ancien qu'une émission ultérieure
    // (renouvellement) → on lui pousse le code courant.
    $presented = (string) ($body['token'] ?? '');
    if ($presented !== '' && ! hash_equals($current['token'], $presented)) {
        json(['status' => 'renewed', 'token' => $current['token']]);
    }

    json(['status' => 'ok']);
}

// ─── GET / : tableau de bord ───
if ($path === '/' && $method === 'GET') {
    $store = new Store($config['db_path']);
    $rows  = $store->all();
    $configured = ! empty($config['private_key']);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Serveur de licence</title>';
    echo '<style>body{font-family:system-ui,sans-serif;margin:2rem;color:#1e293b}'
        . 'h1{font-size:1.4rem}table{border-collapse:collapse;width:100%;margin-top:1rem}'
        . 'th,td{border-bottom:1px solid #e2e8f0;padding:.5rem .75rem;text-align:left;font-size:.85rem}'
        . 'th{background:#0f172a;color:#fff}.r{color:#e11d48;font-weight:700}.a{color:#16a34a;font-weight:700}'
        . '.e{color:#d97706;font-weight:700}.warn{background:#fef2f2;border:1px solid #fecaca;padding:.75rem;border-radius:.5rem}</style>';
    echo '<h1>Serveur de licence — registre</h1>';

    if (! $configured) {
        echo '<p class="warn">Aucune clé privée configurée. Lancez <code>bin/license keygen</code>.</p>';
    }

    if (! $rows) {
        echo '<p>Aucune licence émise.</p>';
    } else {
        echo '<table><tr><th>Identifiant</th><th>Client</th><th>Plan</th><th>Émise</th><th>Échéance</th><th>État</th></tr>';
        foreach ($rows as $r) {
            $state = (int) $r['revoked'] === 1
                ? '<span class="r">révoquée</span>'
                : (($r['expires_at'] < time()) ? '<span class="e">expirée</span>' : '<span class="a">active</span>');
            echo '<tr>'
                . '<td>' . htmlspecialchars($r['identifiant']) . '</td>'
                . '<td>' . htmlspecialchars((string) $r['client_name']) . '</td>'
                . '<td>' . htmlspecialchars($r['plan']) . '</td>'
                . '<td>' . date('Y-m-d', (int) $r['issued_at']) . '</td>'
                . '<td>' . date('Y-m-d', (int) $r['expires_at']) . '</td>'
                . '<td>' . $state . '</td>'
                . '</tr>';
        }
        echo '</table>';
    }
    exit;
}

json(['status' => 'ok', 'message' => 'not found'], 404);
