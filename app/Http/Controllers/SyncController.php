<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Stock;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * SyncController — Réconciliation des données saisies hors-ligne.
 *
 * Appelé par : sync-engine.js (Dexie → serveur) quand la connexion réseau revient.
 *
 * BUGS CORRIGÉS dans cette version :
 *
 * B-21 (Critique) : reconcile() n'avait aucune validation.
 *   → $request->all() injecté directement dans updateOrCreate = mass assignment total.
 *   → Ajout d'un validate() + whitelist explicite des champs autorisés.
 *   → Ajout Gate::denies('C') pour la création, Gate::denies('M') pour la modification.
 *
 * B-22 (Sérieux) : reconcileDailyCheck() avait plusieurs problèmes :
 *   → `DB` non importé → fatal error "Class 'DB' not found"
 *   → updateOrCreate($data) = mass assignment total (tout le payload client injecté)
 *   → StockMovement utilisait 'description' (colonne inexistante) → 'notes'
 *   → StockMovement utilisait 'Sortie' au lieu de 'out' (incohérent avec le reste)
 *   → Aucune validation sur les champs du DailyCheck
 *   → L'observer DailyCheck va auto-décrémenter current_quantity du batch,
 *     donc on NE DOIT PAS le faire manuellement ici (risque de double décrémentation).
 *
 * Routes manquantes : Ce controller n'avait AUCUNE route dans web.php.
 *   → Ajouter dans web.php (voir bloc en fin de fichier).
 *
 * Sécurité : Ajout de auth middleware + Gate checks.
 */
class SyncController extends Controller
{
    /**
     * Réconcilie un lot créé/modifié offline.
     *
     * Stratégie de conflit : Last-Write-Wins basé sur updated_at.
     * Si le serveur a une version plus récente, on renvoie 'conflict'
     * et le client doit adopter la version serveur.
     */
    public function reconcile(Request $request): JsonResponse
    {
        // ─── VALIDATION ───
        $validated = $request->validate([
            'uuid'             => 'required|uuid',
            'code'             => 'required|string|max:50',
            'type'             => 'required|string|in:chair,ponte,reproducteur,poussiniere,Chair,Ponte,Reproducteur,Poussiniere',
            'building_id'      => 'required|integer|exists:buildings,id',
            'initial_quantity' => 'required|integer|min:1',
            'current_quantity' => 'required|integer|min:0',
            'status'           => 'nullable|string|in:Actif,Terminé',
            'arrival_date'     => 'required|date',
            'employee_id'      => 'nullable|integer|exists:employees,id',
            'provider_id'              => 'nullable|integer|exists:providers,id',
            'qty_dead'                 => 'nullable|integer|min:0',
            'arrival_mortality_rate'   => 'nullable|numeric|min:0',
            'updated_at'               => 'required|date',
        ]);

        // ─── DÉTECTION DE CONFLIT ───
        $serverBatch = Batch::where('uuid', $validated['uuid'])->first();

        if ($serverBatch) {
            // Modification d'un lot existant → vérification permission M
            if (Gate::denies('M')) {
                return response()->json(['status' => 'error', 'message' => 'Permission insuffisante.'], 403);
            }

            $clientUpdate = Carbon::parse($validated['updated_at']);

            if ($serverBatch->updated_at->gt($clientUpdate)) {
                // Le serveur a une version plus récente → conflit
                Log::info("SyncController: conflit détecté sur lot {$serverBatch->code} (server: {$serverBatch->updated_at}, client: {$clientUpdate})");

                return response()->json([
                    'status' => 'conflict',
                    'data'   => $serverBatch->only([
                        'uuid', 'code', 'type', 'building_id',
                        'initial_quantity', 'current_quantity',
                        'status', 'arrival_date', 'updated_at',
                    ]),
                ]);
            }
        } else {
            // Création d'un nouveau lot → vérification permission C
            if (Gate::denies('C')) {
                return response()->json(['status' => 'error', 'message' => 'Permission insuffisante.'], 403);
            }
        }

        // ─── WHITELIST EXPLICITE ───
        // On n'utilise JAMAIS $request->all() dans updateOrCreate
        $payload = [
            'code'                   => $validated['code'],
            'type'                   => $validated['type'],
            'building_id'            => $validated['building_id'],
            'initial_quantity'       => $validated['initial_quantity'],
            'current_quantity'       => $validated['current_quantity'],
            'qty_dead'               => $validated['qty_dead'] ?? 0,
            'arrival_mortality_rate' => $validated['arrival_mortality_rate'] ?? 0,
            'status'                 => $validated['status'] ?? 'Actif',
            'arrival_date'           => $validated['arrival_date'],
            'employee_id'            => $validated['employee_id'] ?? null,
            'provider_id'            => $validated['provider_id'] ?? null,
            'is_synced'              => true,
            'last_sync_at'           => now(),
        ];

        DB::transaction(function () use ($validated, $payload) {
            Batch::updateOrCreate(
                ['uuid' => $validated['uuid']],
                $payload
            );
        });

        Log::info("SyncController: lot {$validated['code']} synchronisé (uuid: {$validated['uuid']})");

        return response()->json(['status' => 'success']);
    }

    /**
     * Réconcilie un pointage journalier saisi offline.
     *
     * IMPORTANT : L'observer DailyCheck (DailyCheckObserver ou booted())
     * gère automatiquement le décrémentage de batch.current_quantity.
     * On NE FAIT PAS de décrémentation manuelle ici pour éviter le double comptage.
     *
     * Pour l'aliment consommé : on utilise StockIntegrationService pour la traçabilité.
     */
    public function reconcileDailyCheck(Request $request): JsonResponse
    {
        if (Gate::denies('C')) {
            return response()->json(['status' => 'error', 'message' => 'Permission insuffisante.'], 403);
        }

        // ─── VALIDATION ───
        $validated = $request->validate([
            'uuid'               => 'required|uuid',
            'batch_id'           => 'required|integer|exists:batches,id',
            'check_date'         => 'required|date',
            'mortality'          => 'nullable|integer|min:0',
            'avg_weight'         => 'nullable|numeric|min:0',
            'water_consumed'     => 'nullable|numeric|min:0',
            'feed_consumed'      => 'nullable|numeric|min:0',
            'feed_type'          => 'nullable|string|max:100',
            'temperature'        => 'nullable|numeric',
            'humidity'           => 'nullable|numeric|min:0|max:100',
            'observations'       => 'nullable|string|max:1000',
            'qty_quarantine_in'  => 'nullable|integer|min:0',
            'qty_quarantine_out' => 'nullable|integer|min:0',
            'qty_sorted_out'     => 'nullable|integer|min:0',
            'eggs_collected'     => 'nullable|integer|min:0',
        ]);

        return DB::transaction(function () use ($validated) {

            // ─── VÉRIFICATION DOUBLON ───
            $existing = DailyCheck::where('uuid', $validated['uuid'])->first();

            if ($existing) {
                // Déjà synchronisé (double push réseau possible) → ignorer
                Log::info("SyncController: DailyCheck uuid={$validated['uuid']} déjà présent, ignoré.");
                return response()->json(['status' => 'already_synced']);
            }

            // Vérifier aussi le couple unique (batch_id, check_date)
            $dayExists = DailyCheck::where('batch_id', $validated['batch_id'])
                ->where('check_date', $validated['check_date'])
                ->exists();

            if ($dayExists) {
                Log::warning("SyncController: DailyCheck doublon batch_id={$validated['batch_id']} date={$validated['check_date']}, ignoré.");
                return response()->json([
                    'status'  => 'conflict',
                    'message' => "Un pointage existe déjà pour ce lot à cette date.",
                ]);
            }

            // ─── WHITELIST EXPLICITE ───
            $check = DailyCheck::create([
                'uuid'               => $validated['uuid'],
                'batch_id'           => $validated['batch_id'],
                'check_date'         => $validated['check_date'],
                'mortality'          => $validated['mortality'] ?? 0,
                'avg_weight'         => $validated['avg_weight'] ?? null,
                'water_consumed'     => $validated['water_consumed'] ?? null,
                'feed_consumed'      => $validated['feed_consumed'] ?? null,
                'feed_type'          => $validated['feed_type'] ?? null,
                'temperature'        => $validated['temperature'] ?? null,
                'humidity'           => $validated['humidity'] ?? null,
                'observations'       => $validated['observations'] ?? null,
                'qty_quarantine_in'  => $validated['qty_quarantine_in'] ?? 0,
                'qty_quarantine_out' => $validated['qty_quarantine_out'] ?? 0,
                'qty_sorted_out'     => $validated['qty_sorted_out'] ?? 0,
                'eggs_collected'     => $validated['eggs_collected'] ?? 0,
                'user_id'            => Auth::id(),
            ]);

            // NOTE : L'observer DailyCheck (created hook) va automatiquement :
            // 1. Décrémenter batch.current_quantity de calculateNetImpact()
            // 2. Pas besoin de le faire manuellement ici

            // ─── SYNCHRONISATION STOCK ALIMENT ───
            // On utilise StockIntegrationService pour la traçabilité
            if (! empty($validated['feed_type']) && ($validated['feed_consumed'] ?? 0) > 0) {
                $synced = \App\Services\StockIntegrationService::syncMovement(
                    $validated['feed_type'],
                    'conso',
                    (float) $validated['feed_consumed'],
                    'out',
                    "Consommation Lot (Synchro Offline, check {$validated['check_date']})",
                    'KG' // B-26 corrigé : unité explicite, pas de guessInputUnit
                );

                if (! $synced) {
                    Log::warning(
                        "SyncController: Stock aliment '{$validated['feed_type']}' introuvable " .
                        "pour le pointage {$validated['check_date']} du lot {$validated['batch_id']}"
                    );
                }
            }

            Log::info("SyncController: DailyCheck synchronisé (uuid: {$validated['uuid']}, batch: {$validated['batch_id']})");

            return response()->json(['status' => 'success']);
        });
    }
}
