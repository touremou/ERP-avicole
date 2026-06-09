<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChickDispatch extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'incubation_id', 'destination_type', 'quantity',
        'batch_id', 'client_id', 'unit_price', 'total_amount',
        'quality_grade', 'notes', 'dispatched_by', 'dispatch_date',
    ];

    protected $casts = [
        'dispatch_date' => 'date',
        'unit_price'    => 'float',
        'total_amount'  => 'float',
    ];

    public function incubation(): BelongsTo { return $this->belongsTo(Incubation::class); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function dispatcher(): BelongsTo { return $this->belongsTo(User::class, 'dispatched_by'); }

    public function getDestinationLabelAttribute(): string
    {
        return match($this->destination_type) {
            'elevage' => '🏠 Démarrage ' . ($this->batch?->code ?? 'lot'),
            'vente'   => '💰 Vente ' . ($this->client?->name ?? 'client'),
            'stock'   => '📦 Stock poussins',
            'perte'   => '⚠️ Perte / Non-viable',
            default   => $this->destination_type,
        };
    }
}
