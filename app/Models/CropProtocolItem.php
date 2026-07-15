<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape d'un protocole / itinéraire technique de culture.
 *
 * Pendant végétal de `ProtocolStep` : une intervention datée en jours après
 * semis (DAP), avec produit suggéré, dose et méthode d'application.
 */
class CropProtocolItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'crop_protocol_id', 'day_number', 'stage', 'action_name',
        'type', 'product_suggested', 'dose', 'method', 'notes',
    ];

    protected $casts = [
        'day_number' => 'integer',
    ];

    // ─── RELATIONS ───

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(CropProtocol::class, 'crop_protocol_id');
    }

    /**
     * Correspondance type d'étape → type d'intrant de cycle (CropInput::TYPES),
     * pour comptabiliser une étape réalisée comme charge du cycle. Les étapes
     * sans contrepartie de charge (observation, récolte) renvoient null.
     */
    public const INPUT_TYPE_MAP = [
        'semis'         => 'semence',
        'fertilisation' => 'engrais',
        'traitement'    => 'phyto',
        'irrigation'    => 'irrigation',
        'sarclage'      => 'main_doeuvre',
        'autre'         => 'autre',
    ];

    /** Type d'intrant suggéré pour comptabiliser cette étape (ou null). */
    public function suggestedInputType(): ?string
    {
        return self::INPUT_TYPE_MAP[$this->type] ?? null;
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return CropProtocol::ITEM_TYPES[$this->type]['label'] ?? ucfirst((string) $this->type);
    }

    public function getTypeIconAttribute(): string
    {
        return CropProtocol::ITEM_TYPES[$this->type]['icon'] ?? 'fa-circle';
    }

    public function getTypeColorAttribute(): string
    {
        return CropProtocol::ITEM_TYPES[$this->type]['color'] ?? 'slate';
    }
}
