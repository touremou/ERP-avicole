<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasStandardUuid;
use App\Traits\BelongsToFarm;
use App\Traits\AuditsChanges;

/**
 * Expense — Registre des dépenses ponctuelles (charges diverses, cash).
 *
 * Une dépense peut être :
 *   - GÉNÉRALE   : rattachée à la ferme uniquement (frais généraux) ;
 *   - DIRECTE    : rattachée à un lot (batch_id) → impacte sa marge nette.
 *
 * Seules les dépenses au statut « valide » entrent dans les résultats
 * financiers (P&L) — mécanisme de confiance contre les saisies non contrôlées.
 */
class Expense extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm, AuditsChanges;

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'reference', 'farm_id', 'batch_id', 'user_id',
        'category', 'label', 'amount', 'expense_date', 'payment_method',
        'status', 'supplier_name', 'notes', 'justificatif_path',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'is_synced'    => 'boolean',
        'last_sync_at' => 'datetime',
        'amount'       => 'decimal:2',
        'expense_date' => 'date',
        'approved_at'  => 'datetime',
    ];

    // ─── TAXONOMIE (VARCHAR extensible, pas d'ENUM figé) ───

    /** Catégories de dépenses (clé stockée => libellé FR). */
    public const CATEGORIES = [
        'carburant'           => 'Carburant',
        'transport'           => 'Transport / Déplacement',
        'entretien'           => 'Entretien / Réparation',
        'fournitures'         => 'Fournitures & petit matériel',
        'communication'       => 'Communication (crédit, internet)',
        'administratif'       => 'Frais administratifs',
        'taxes'               => 'Taxes & impôts',
        'location'            => 'Location',
        'main_oeuvre'         => "Main-d'œuvre journalière",
        'sante_animale'       => 'Santé animale (achat ponctuel)',
        'eau_energie'         => 'Eau & énergie (appoint)',
        'divers'              => 'Divers',
    ];

    /** Modes de paiement (clé stockée => libellé FR). */
    public const PAYMENT_METHODS = [
        'especes'      => 'Espèces',
        'mobile_money' => 'Mobile Money (OM / MoMo)',
        'virement'     => 'Virement',
        'cheque'       => 'Chèque',
    ];

    public const STATUSES = ['en_attente', 'valide', 'annule'];

    // ─── RELATIONS ───

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ─── SCOPES ───

    /** Dépenses validées (les seules comptées dans les résultats). */
    public function scopeValidated($query)
    {
        return $query->where('status', 'valide');
    }

    public function scopeBetweenDates($query, $from, $to)
    {
        return $query->whereBetween('expense_date', [$from, $to]);
    }

    public function scopeByCategory($query, ?string $category)
    {
        return $category ? $query->where('category', $category) : $query;
    }

    public function scopeByStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    // ─── ACCESSORS ───

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? ucfirst((string) $this->payment_method);
    }

    public function getIsValidatedAttribute(): bool
    {
        return $this->status === 'valide';
    }
}
