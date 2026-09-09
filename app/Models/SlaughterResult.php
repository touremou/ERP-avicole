<?php
// ═══════════════════════════════════════════════
// Ce fichier contient 5 models à séparer dans
// des fichiers individuels lors de l'installation
// ═══════════════════════════════════════════════

// ─── 1. SlaughterResult.php ───

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class SlaughterResult extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id',
        'slaughter_order_id', 'total_carcass_weight_kg', 'carcass_yield_percent', 'presentation',
        'condemned_count', 'condemned_reason',
        'avg_live_weight_kg', 'avg_carcass_weight_kg',
        'execution_date', 'inspector_notes',
    ];

    protected $casts = [
        'total_carcass_weight_kg' => 'decimal:2',
        'carcass_yield_percent'   => 'decimal:2',
        'avg_live_weight_kg'      => 'decimal:3',
        'avg_carcass_weight_kg'   => 'decimal:3',
        'execution_date'          => 'date',
    ];

    /**
     * COHÉRENCE D'UNE PESÉE D'ABATTAGE — déclaration UNIQUE.
     *
     * Rend [champ => motif], vide si tout se tient. Les deux portes la lisent :
     * l'écran d'exécution (erreurs de champ) et la synchro terrain
     * (`ValidationException`, donc refus DÉFINITIF — une pesée fausse ne se
     * corrige pas toute seule au rejeu).
     *
     * ─── POURQUOI ELLE EST ICI ───
     *
     * Ces deux règles vivaient dans le seul contrôleur web. `executeSlaughter`
     * — le service PARTAGÉ par le bureau et le terrain — calculait
     * `$actualQty - $condemned` sans jamais vérifier le signe.
     *
     * Mesuré, par la synchro : 50 sujets abattus, 60 saisis à l'inspection.
     * Accepté (« success »), et alors :
     *
     *   • `condemned_count` vaut 60 en base, donc le rapport HACCP
     *     (Σ condemned_count) compte plus de saisies que d'abattages ;
     *   • `avg_carcass_weight_kg` tombe à ZÉRO en silence — le diviseur étant
     *     négatif, la garde `> 0` l'écrase ;
     *   • 70 kg entrent en produits finis avec ZÉRO pièce : la viande existe au
     *     kilo et n'existe pas à la tête.
     *
     * Le même formulaire au bureau refuse ces chiffres.
     *
     * La règle du POIDS est déjà tenue des deux côtés (le contrôleur ici, la
     * validation `lte:total_live_weight_kg` côté synchro). On la remonte quand
     * même : une carcasse plus lourde que l'animal vivant est une création de
     * matière, et cette borne doit vivre là où le calcul se fait, pas seulement
     * aux portes.
     *
     * @return array<string,string>
     */
    public static function weighingRefusals(
        float $liveKg,
        float $carcassKg,
        int $actualQty,
        int $condemned,
    ): array {
        $refusals = [];

        if ($carcassKg > $liveKg) {
            $refusals['total_carcass_weight_kg'] = "Alerte Système : le poids carcasse ({$carcassKg} kg) "
                . "ne peut pas dépasser le poids vif ({$liveKg} kg). Vérifiez les deux pesées.";
        }

        if ($condemned > $actualQty) {
            $refusals['condemned_count'] = "Le nombre de saisies sanitaires ({$condemned}) ne peut pas "
                . "dépasser le nombre de sujets abattus ({$actualQty}).";
        }

        return $refusals;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SlaughterOrder::class, 'slaughter_order_id');
    }

    public function getYieldStatusAttribute(): string
    {
        if ($this->carcass_yield_percent >= 73) return 'excellent';
        if ($this->carcass_yield_percent >= 70) return 'bon';
        if ($this->carcass_yield_percent >= 65) return 'acceptable';
        return 'faible';
    }
}
