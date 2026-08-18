<?php

namespace App\Actions\Slaughter;

use App\Models\CcpRecord;
use App\Models\SlaughterOrder;
use App\Models\TemperatureLog;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Relevé CCP — la conformité est calculée ICI, côté serveur, selon les
 * seuils paramétrables (Réglages > abattoir) ; le client ne fait que
 * proposer ses mesures. Un CCP non conforme rattaché à un ordre le
 * BLOQUE automatiquement (RG-02) — la libération est réservée au niveau
 * abattoir.S avec motif obligatoire.
 */
class RecordCcp
{
    /**
     * @param array{ccp:string, slaughter_order_id?:int|null, equipment_ref?:string|null,
     *              mesures:array, conforme?:bool|null, corrective_action?:string|null,
     *              operator_id:int, releve_at:mixed, corrects_record_id?:int|null,
     *              corrected_by_id?:int|null, uuid?:string} $data
     */
    public function execute(array $data): CcpRecord
    {
        return DB::transaction(function () use ($data) {
            $conforme = $this->evaluate($data['ccp'], $data['mesures'], $data['conforme'] ?? null);

            $record = CcpRecord::create([
                'ccp'                => $data['ccp'],
                'slaughter_order_id' => $data['slaughter_order_id'] ?? null,
                'equipment_ref'      => $data['equipment_ref'] ?? null,
                'mesures'            => $data['mesures'],
                'conforme'           => $conforme,
                'corrective_action'  => $data['corrective_action'] ?? null,
                'operator_id'        => $data['operator_id'],
                'releve_at'          => $data['releve_at'],
                'synced_at'          => now(),
                'corrects_record_id' => $data['corrects_record_id'] ?? null,
                'corrected_by_id'    => $data['corrected_by_id'] ?? null,
            ]);

            // RG-02 : non conforme + ordre → blocage automatique du lot.
            if (! $conforme && $record->slaughter_order_id) {
                $order = SlaughterOrder::lockForUpdate()->find($record->slaughter_order_id);

                if ($order && $order->status !== 'bloque') {
                    app(BlockSlaughterOrder::class)->execute(
                        $order,
                        'Blocage automatique — ' . CcpRecord::labelFor($record->ccp)
                        . ' non conforme (relevé #' . $record->id . ')',
                        $data['operator_id'],
                    );
                }
            }

            if (! $conforme) {
                $this->alert($record);
            }

            return $record;
        });
    }

    /**
     * Conformité selon les seuils Settings. Les CCP sans règle numérique
     * (ccp1, ccp2 sans total) s'appuient sur l'appréciation déclarée.
     * Publique : la sync la pré-calcule pour exiger l'action corrective
     * AVANT d'écrire (pas d'alerte partie sur une écriture annulée).
     */
    public function evaluate(string $ccp, array $mesures, ?bool $declared): bool
    {
        switch ($ccp) {
            case CcpRecord::CCP3:
                if (isset($mesures['temperature_coeur'])) {
                    $max = self::seuil('ccp3_core_temp_max');

                    if ($max !== null) {
                        return (float) $mesures['temperature_coeur'] <= $max;
                    }
                }
                break;

            case CcpRecord::CCP2:
                if (isset($mesures['carcasses_souillees'], $mesures['carcasses_total'])
                    && (int) $mesures['carcasses_total'] > 0) {
                    $max = self::seuil('ccp2_soiled_max_pct');

                    if ($max !== null) {
                        $pct = 100 * (int) $mesures['carcasses_souillees'] / (int) $mesures['carcasses_total'];

                        return $pct <= $max;
                    }
                }
                break;

            case CcpRecord::CCP4:
                if (isset($mesures['temperature'], $mesures['point'])) {
                    return TemperatureLog::isCompliant((string) $mesures['point'], (float) $mesures['temperature']);
                }
                if (isset($mesures['temperature'])) {
                    $max = self::seuil('cold_positive_max');

                    if ($max !== null) {
                        return (float) $mesures['temperature'] <= $max;
                    }
                }
                break;
        }

        return $declared ?? true;
    }

    /**
     * Le seuil paramétré, ou null s'il n'est pas renseigné.
     *
     * Ces trois comparaisons coulaient le réglage en float sans rien vérifier. Un
     * seuil effacé par mégarde à l'écran des Réglages devenait donc 0 : toute
     * carcasse à 2 °C était déclarée NON CONFORME, une alerte HACCP « critique »
     * partait à chaque relevé, et l'ordre d'abattage était BLOQUÉ. Un champ vidé
     * arrêtait la production.
     *
     * Faute de seuil, on ne condamne pas : l'appréciation déclarée par l'opérateur
     * fait foi — c'est déjà le repli de cette méthode pour les CCP sans règle
     * numérique. Aucune valeur de secours n'est inventée ici : les seuils de
     * référence vivent dans la migration qui les sème, et les recopier en ferait
     * une seconde déclaration.
     *
     * `0` déclaré reste un vrai seuil.
     */
    private static function seuil(string $key): ?float
    {
        // `rawValue` et non `setting()` : le cast d'un réglage numérique
        // transforme la chaîne vide en 0 — précisément la confusion à lever.
        $value = \App\Models\Setting::rawValue("abattoir.{$key}");

        return ($value === null || $value === '') ? null : (float) $value;
    }

    private function alert(CcpRecord $record): void
    {
        try {
            $subject = $record->slaughterOrder?->order_number ?? $record->equipment_ref ?? '—';

            app(NotificationHub::class)->alertHaccp(
                '🚨 ' . CcpRecord::labelFor($record->ccp) . " NON CONFORME — {$subject}. "
                . 'Mesures : ' . json_encode($record->mesures, JSON_UNESCAPED_UNICODE) . '. '
                . 'Action : ' . ($record->corrective_action ?: 'à définir')
                . ($record->slaughter_order_id ? ' — ordre BLOQUÉ.' : ''),
                'CCP non conforme',
                'critique',
            );
        } catch (\Throwable $e) {
            Log::warning("CCP {$record->id}: alerte non envoyée : {$e->getMessage()}");
        }
    }
}
