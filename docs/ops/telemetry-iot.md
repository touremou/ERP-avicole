# Télémétrie IoT — ingestion découplée (température bâtiments)

> Architecture **future-proof** : le matériel n'est pas choisi. L'ERP expose un
> contrat générique ; passerelle HTTP directe ou broker MQTT (Mosquitto → appel
> HTTP), les deux standards de l'industrie fonctionneront sans refactoring.

## 1. Endpoint

```
POST /api/v1/telemetry/temperature
Header : X-Api-Key: <TELEMETRY_API_KEY>
```

- Clé définie dans `.env` (`TELEMETRY_API_KEY`). **Vide = endpoint désactivé
  (503)** — sécurité par défaut.
- Throttle HTTP : 120 req/min (borne un capteur fou avant même l'écrêtage).

## 2. Contrat de charge utile (STRICT et immuable)

```json
{
  "sensor_id": "TH-001",
  "timestamp": "2026-07-05T08:16:00+00:00",
  "value": 27.5,
  "unit": "celsius"
}
```

| Champ | Règle |
|---|---|
| `sensor_id` | string ≤ 64, identifiant matériel unique |
| `timestamp` | ISO 8601 — **heure exacte du relevé** (pas de l'envoi) |
| `value` | décimal, plausibilité −30…+60 °C (sinon 422) |
| `unit` | `celsius` uniquement |

Réponses : `201 accepted` (stocké) · `202 throttled` (écrêté, cf. §3) ·
`401` (clé) · `422` (contrat) · `503` (désactivé).

## 3. Anti-spam (capteur mal configuré)

Un relevé n'est **persisté** que s'il apporte de l'information :
variation ≥ `telemetry.min_delta_c` (défaut **0,3 °C**) OU intervalle ≥
`telemetry.min_interval_seconds` (défaut **300 s**) depuis le dernier relevé du
même capteur. Sinon `202` sans écriture — un capteur qui émet toutes les 500 ms
ne fait PAS exploser la base. Rétention : `telemetry:prune` (90 j, planifié).

## 4. Zone tampon & worker (aucun verrou sur les tables métier)

L'endpoint écrit dans **`telemetry_logs` uniquement**. Le worker
`telemetry:process` (planifié **toutes les 5 min**) associe chaque relevé
`pending` au **lot actif du bâtiment au moment du relevé** (lieu + heure) →
`linked`. Capteur hors registre → `orphan` (visible, jamais perdu).

Registre : table **`telemetry_sensors`** (`sensor_id` → `building_id`).
L'affectation se fait en base/console tant que le matériel n'est pas choisi :

```bash
php artisan tinker --execute="App\Models\TelemetrySensor::create(['sensor_id' => 'TH-001', 'building_id' => 1, 'farm_id' => 1, 'label' => 'Sonde bâtiment A']);"
```

## 5. Température hybride au pointage (IoT + manuel)

- Le formulaire de pointage propose un bouton **« Capteur min–max »** quand des
  relevés du jour existent pour le bâtiment ; l'appliquer trace
  `temp_source = iot` + identifiant du capteur. Toute retouche clavier repasse
  en `manuel` + nom de l'opérateur (`temp_recorded_by`).
- **Résolution de conflit : la saisie manuelle PRIME**, mais un écart
  > `telemetry.calibration_gap_c` (défaut **2 °C**) avec le capteur du jour
  déclenche une alerte « vérifier la calibration » (non bloquante).
- **Anti fat-finger** : bornes strictes −10…+50 °C sur la saisie manuelle
  (« 220 » au lieu de « 22.0 » est refusé) + `temp_max ≥ temp_min`.
- L'historique du lot affiche l'origine de chaque température (icône puce =
  capteur, icône personne = opérateur, nom au survol).
- **Mode dégradé tablette** : la saisie hors-ligne passe par le mécanisme PWA
  existant (IndexedDB + `POST /sync/push` idempotent à uuid) — l'horodatage
  saisi est conservé à la synchronisation, la chronologie du lot n'est pas
  faussée.

## 6. Réglages

| Clé setting | Défaut | Rôle |
|---|---|---|
| `telemetry.min_delta_c` | 0.3 | Variation minimale persistée (°C) |
| `telemetry.min_interval_seconds` | 300 | Intervalle minimal persisté (s) |
| `telemetry.calibration_gap_c` | 2 | Écart manuel/capteur déclenchant l'alerte |

Tests : `tests/Feature/TelemetryIngestionTest.php` (8).
