<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;
use App\Traits\AuditsChanges;

class Payment extends Model
{
    use BelongsToFarm, AuditsChanges;
    protected $fillable = [
        'farm_id', 'uuid', 'sale_id', 'amount', 'payment_date',
        'method', 'treasury_account_id', 'reference', 'received_by', 'notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** Modes d'encaissement d'une vente — ceux du bureau, du terrain et des libellés ci-dessous. */
    public const METHODES = ['especes', 'orange_money', 'virement', 'cheque'];

    /**
     * Le mode d'encaissement dans la langue des ventes. L'écran de vente du
     * terrain proposait « mobile_money » quand l'écran de règlement, le bureau
     * et ce modèle disent « orange_money » : l'encaissement passait, mais sans
     * libellé, et hors du total « Orange Money du jour ». Les opérations déjà en
     * file sur les téléphones sont traduites ici.
     */
    public static function normaliserMethode(?string $methode): ?string
    {
        return $methode === 'mobile_money' ? 'orange_money' : $methode;
    }

    public function getMethodLabelAttribute(): string
    {
        return match ($this->method) {
            'especes'      => 'Espèces',
            'orange_money' => 'Orange Money',
            'virement'     => 'Virement bancaire',
            'cheque'       => 'Chèque',
            default        => $this->method,
        };
    }
}
