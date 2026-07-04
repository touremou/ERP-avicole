<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelemetryLog;
use App\Models\TelemetrySensor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ingestion IoT — endpoint GÉNÉRIQUE et découplé (exigence 3 pré-MEP).
 *
 * Contrat de charge utile STRICT et immuable (tout futur fournisseur —
 * passerelle HTTP directe ou broker MQTT→HTTP — devra le respecter) :
 *   { "sensor_id": "...", "timestamp": "ISO 8601", "value": 27.5, "unit": "celsius" }
 *
 * Principes :
 * - Authentification par clé d'API (header X-Api-Key = TELEMETRY_API_KEY).
 *   Clé non configurée → ingestion désactivée (503), sécurité par défaut.
 * - ZONE TAMPON : écrit dans telemetry_logs UNIQUEMENT — jamais dans les
 *   tables métier (aucun verrou possible sur le suivi de lot). Le worker
 *   `telemetry:process` fait l'association lot/lieu/heure hors requête.
 * - ANTI-SPAM (capteur mal configuré qui émet toutes les 500 ms) : un relevé
 *   n'est persisté que s'il apporte de l'information — variation
 *   significative (≥ telemetry.min_delta_c, défaut 0,3 °C) OU intervalle
 *   écoulé (≥ telemetry.min_interval_seconds, défaut 300 s) depuis le
 *   dernier relevé du même capteur. Sinon : 202 « throttled », sans écriture.
 */
class TelemetryController extends Controller
{
    public function storeTemperature(Request $request): JsonResponse
    {
        // ── 1. Clé d'API ──
        $expectedKey = (string) config('services.telemetry.api_key');
        if ($expectedKey === '') {
            return response()->json(['status' => 'disabled', 'message' => 'Ingestion IoT non configurée (TELEMETRY_API_KEY).'], 503);
        }
        if (! hash_equals($expectedKey, (string) $request->header('X-Api-Key'))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        // ── 2. Contrat strict ──
        $data = $request->validate([
            'sensor_id' => 'required|string|max:64',
            'timestamp' => 'required|date',
            'value'     => 'required|numeric|between:-30,60', // plausibilité physique bâtiment
            'unit'      => 'required|in:celsius',
        ]);

        $recordedAt = Carbon::parse($data['timestamp']);
        $value      = round((float) $data['value'], 2);

        // ── 3. Écrêtage anti-spam (dernier relevé du même capteur) ──
        $last = TelemetryLog::where('sensor_id', $data['sensor_id'])
            ->orderByDesc('recorded_at')
            ->first();

        if ($last) {
            $intervalOk = abs($recordedAt->diffInSeconds($last->recorded_at))
                >= (int) setting('telemetry.min_interval_seconds', 300);
            $deltaOk = abs($value - (float) $last->value)
                >= (float) setting('telemetry.min_delta_c', 0.3);

            if (! $intervalOk && ! $deltaOk) {
                return response()->json(['status' => 'throttled'], 202);
            }
        }

        // ── 4. Résolution du lieu (registre capteurs) + écriture TAMPON ──
        $sensor = TelemetrySensor::where('sensor_id', $data['sensor_id'])
            ->where('is_active', true)
            ->first();
        $sensor?->update(['last_seen_at' => now()]);

        $log = TelemetryLog::create([
            'farm_id'     => $sensor?->farm_id,
            'sensor_id'   => $data['sensor_id'],
            'metric'      => 'temperature',
            'value'       => $value,
            'unit'        => 'celsius',
            'recorded_at' => $recordedAt,
            'building_id' => $sensor?->building_id,
            'status'      => TelemetryLog::STATUS_PENDING,
            'created_at'  => now(),
        ]);

        return response()->json(['status' => 'accepted', 'id' => $log->id], 201);
    }
}
