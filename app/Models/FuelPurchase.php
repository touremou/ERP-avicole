<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;
use App\Models\Building;

class FuelPurchase extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'energy_source_id', 'building_id', 'purchase_date', 'user_id',
        'quantity_liters', 'unit_price', 'total_cost',
        'supplier', 'receipt_reference',
        'fuel_level_after', 'notes', 'expense_id',
    ];

    protected $casts = [
        'purchase_date'     => 'date',
        'quantity_liters'   => 'decimal:2',
        'unit_price'        => 'decimal:2',
        'total_cost'        => 'decimal:2',
        'fuel_level_after'  => 'decimal:2',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(EnergySource::class, 'energy_source_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Écriture comptable miroir au registre des dépenses (catégorie carburant).
     *
     * Un achat de gasoil est À LA FOIS un mouvement opérationnel (remplissage de
     * cuve, suivi dans le module Énergie) ET une sortie de trésorerie. Plutôt
     * que de tenir deux comptabilités parallèles, l'achat poste une dépense
     * « valide » unique : elle apparaît dans le registre Dépenses et alimente le
     * P&L. Le rapport de résultat lit le poste Gasoil DEPUIS ce registre (et non
     * la table fuel_purchases) — source unique, zéro double comptage.
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** Crée (ou met à jour) la dépense carburant liée à cet achat. */
    public function syncLedgerExpense(): Expense
    {
        $liters = rtrim(rtrim(number_format((float) $this->quantity_liters, 2, '.', ''), '0'), '.');

        $attributes = [
            'farm_id'       => $this->farm_id,
            'user_id'       => $this->user_id,
            'category'      => 'carburant',
            'label'         => 'Carburant — ' . ($this->source?->name ?? 'cuve') . " ({$liters} L)",
            'amount'        => $this->total_cost,
            'expense_date'  => $this->purchase_date,
            'status'        => 'valide', // achat confirmé (trésorerie sortie) → compté en P&L
            'supplier_name' => $this->supplier,
            'notes'         => $this->receipt_reference ? ('Réf. reçu : ' . $this->receipt_reference) : null,
        ];

        if ($this->expense) {
            $this->expense->update($attributes);

            return $this->expense;
        }

        $lastId = Expense::withoutGlobalScopes()->withTrashed()->max('id') ?? 0;
        $expense = Expense::create($attributes + ['reference' => sprintf('GAS-%05d', $lastId + 1)]);

        $this->expense_id = $expense->id;
        $this->saveQuietly();

        return $expense;
    }
}
