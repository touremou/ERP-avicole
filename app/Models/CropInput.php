<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Intrant de culture (module Production Végétale).
 *
 * Ligne de charge itémisée rattachée à un cycle de culture. Pendant végétal de
 * `FeedPurchase` : alimente le calcul de la marge nette du cycle.
 */
class CropInput extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    /** Catégories d'intrants (colonne `type`). */
    public const TYPES = [
        'semence'      => 'Semence',
        'engrais'      => 'Engrais',
        'phyto'        => 'Produit phytosanitaire',
        'irrigation'   => 'Irrigation',
        'main_doeuvre' => "Main d'œuvre",
        'carburant'    => 'Carburant',
        'autre'        => 'Autre',
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'crop_cycle_id', 'provider_id',
        'type', 'name', 'quantity', 'unit', 'unit_cost', 'total_cost',
        'input_date', 'preharvest_days', 'synced_to_stock', 'stock_item_name', 'notes',
    ];

    protected $casts = [
        'is_synced'       => 'boolean',
        'last_sync_at'    => 'datetime',
        'input_date'      => 'date',
        'preharvest_days' => 'integer',
        'quantity'        => 'decimal:3',
        'unit_cost'       => 'decimal:2',
        'total_cost'      => 'decimal:2',
        'synced_to_stock' => 'boolean',
    ];

    // ─── DÉLAI AVANT RÉCOLTE (DAR — résidus phytosanitaires) ───

    /**
     * Date de FIN du délai avant récolte : à partir de ce jour, la production
     * du cycle est récoltable. Null si l'intrant n'impose aucun délai
     * (engrais, irrigation, main d'œuvre…).
     */
    public function getHarvestAllowedFromAttribute(): ?\Carbon\Carbon
    {
        if (! $this->preharvest_days || ! $this->input_date) {
            return null;
        }

        return $this->input_date->copy()->addDays((int) $this->preharvest_days);
    }

    /**
     * Le délai avant récolte court-il encore À LA DATE DONNÉE (aujourd'hui par
     * défaut) ?
     *
     * Le paramètre rend la garde EXACTE plutôt qu'approchée. Ce qui compte
     * physiquement, ce sont les résidus dans le produit récolté : donc la date de
     * RÉCOLTE comparée à date d'application + délai. Tant que la saisie est
     * immédiate, les deux coïncident ; elles divergent dès qu'on enregistre une
     * récolte de la veille ou qu'on reprend un historique — cas où comparer à
     * « aujourd'hui » bloquerait une récolte qui a bel et bien eu lieu avant
     * l'échéance… ou après le traitement.
     */
    public function blocksHarvest(?\Carbon\Carbon $harvestDate = null): bool
    {
        $from = $this->harvest_allowed_from;

        if ($from === null || $this->input_date === null) {
            return false;
        }

        $date = ($harvestDate ?? now())->copy()->startOfDay();

        // FENÊTRE BORNÉE DES DEUX CÔTÉS. Une récolte n'est concernée que si elle
        // tombe entre l'application et l'échéance : [date_application, échéance[.
        //
        // La borne BASSE compte : une récolte faite AVANT le traitement n'en
        // porte pas les résidus. Sans elle, reprendre un historique refuserait
        // des récoltes parfaitement légitimes au seul motif qu'un traitement
        // postérieur court encore.
        return $date->gte($this->input_date->copy()->startOfDay())
            && $date->lt($from->copy()->startOfDay());
    }

    /** Jours restants avant récolte autorisée (0 si purgé/sans délai). */
    public function getPreharvestDaysLeftAttribute(): int
    {
        $from = $this->harvest_allowed_from;

        return $from === null ? 0 : max(0, now()->startOfDay()->diffInDays($from, false));
    }

    // ─── RELATIONS ───

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
