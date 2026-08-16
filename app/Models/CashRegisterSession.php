<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CashRegisterSession — session de caisse (ouverture → clôture).
 *
 * Théorique attendu en espèces = fond d'ouverture + encaissements espèces NETS
 * (remboursements déduits) effectués pendant la session. Le comptage des billets
 * à la clôture donne le réel ; l'écart = réel − théorique.
 */
class CashRegisterSession extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'user_id', 'treasury_account_id', 'status',
        'opened_at', 'opening_float', 'closed_at',
        'expected_cash', 'counted_cash', 'difference', 'denominations', 'notes',
    ];

    protected $casts = [
        'opened_at'     => 'datetime',
        'closed_at'     => 'datetime',
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash'  => 'decimal:2',
        'difference'    => 'decimal:2',
        'denominations' => 'array',
    ];

    /** Coupures GNF proposées au comptage (de la plus grande à la plus petite). */
    public const DENOMINATIONS = [20000, 10000, 5000, 2000, 1000, 500, 100];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Espèces théoriques actuellement en caisse = fond + encaissements espèces
     * NETS (remboursements négatifs inclus) depuis l'ouverture.
     */
    public function expectedCash(): float
    {
        $end = $this->closed_at ?? now();

        // ENTRÉES : encaissements en espèces de la session. La somme est SIGNÉE
        // — un remboursement client est un paiement négatif, il sort donc déjà
        // du tiroir par cette ligne.
        $netCash = (float) Payment::where('method', 'especes')
            ->whereBetween('created_at', [$this->opened_at, $end])
            ->sum('amount');

        return round((float) $this->opening_float + $netCash - $this->cashPaidOut($end), 2);
    }

    /**
     * SORTIES D'ESPÈCES DU TIROIR pendant la session — hors encaissements.
     *
     * Ce montant manquait, et le manque se voyait à l'endroit le plus sensible :
     * une dépense payée en espèces sort physiquement de la caisse, mais
     * `expectedCash()` ne comptait que les ENCAISSEMENTS. Le comptage du soir
     * annonçait donc un MANQUANT égal au montant sorti.
     *
     * Mesuré : fond 1 000 000, encaissé 500 000, gasoil payé 300 000. Le tiroir
     * contient 1 200 000, le code en attendait 1 500 000 — « manquant de
     * 300 000 », et l'ALERTE ANTI-FRAUDE partait au promoteur, à l'étranger,
     * sur une opération parfaitement régulière.
     *
     * C'est le pire endroit pour un faux positif : une alerte de détournement
     * qui se déclenche sur la routine finit par ne plus être lue, et le jour où
     * l'écart est réel, il se lit comme les autres.
     *
     * ─── POURQUOI LE GRAND-LIVRE, ET PAS LES SEULES DÉPENSES ───
     *
     * Le tiroir ne paie pas que des dépenses : règlement fournisseur, salaire
     * remis en main propre, tout passe par le compte de caisse. On lit donc les
     * SORTIES du compte, ce qui les couvre toutes — présentes et à venir.
     *
     * DEUX EXCLUSIONS, chacune pour éviter un double comptage :
     *
     *   • les écritures issues d'un PAIEMENT : un remboursement est déjà compté
     *     en négatif dans la somme signée ci-dessus ;
     *   • l'écriture de CLÔTURE, qui aligne le compte sur le comptant physique —
     *     la faire entrer dans le calcul de ce même comptant serait circulaire.
     */
    private function cashPaidOut(\Illuminate\Support\Carbon|\DateTimeInterface $end): float
    {
        $accountId = $this->treasury_account_id
            ?: TreasuryAccount::active()->where('type', 'caisse')->value('id');

        if (! $accountId) {
            return 0.0;
        }

        return (float) TreasuryTransaction::where('treasury_account_id', $accountId)
            ->where('direction', 'out')
            ->where('category', '!=', 'cloture_caisse')
            ->where(function ($q) {
                $q->whereNull('source_type')
                  ->orWhere('source_type', '!=', (new Payment)->getMorphClass());
            })
            ->whereBetween('created_at', [$this->opened_at, $end])
            ->sum('amount');
    }
}
