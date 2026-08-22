<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;

class Client extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'client_id', 'name', 'type', 'category', 'price_list_id',
        'phone', 'email', 'address',
        'nif', 'rccm',
        'credit_limit', 'balance',
        'status', 'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'balance'      => 'decimal:2',
    ];

    // ─── RELATIONS ───

    public function priceList(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalePriceList::class, 'price_list_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasManyThrough(Payment::class, Sale::class);
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('status', 'actif');
    }

    public function scopeWithDebt($query)
    {
        return $query->where('balance', '>', 0);
    }

    public function scopeOverCreditLimit($query)
    {
        return $query->whereRaw('balance > credit_limit AND credit_limit > 0');
    }

    // ─── ACCESSORS ───

    public function getIsOverLimitAttribute(): bool
    {
        return $this->credit_limit > 0 && $this->balance > $this->credit_limit;
    }

    public function getAvailableCreditAttribute(): float
    {
        if ($this->credit_limit <= 0) return 0;
        return max(0, $this->credit_limit - $this->balance);
    }

    // ─── METHODS ───

    /**
     * POURQUOI ce client ne peut pas prendre CE crédit — null s'il le peut.
     *
     * Règle unique du crédit client, et le seul frein sur l'argent qui sort de
     * l'exploitation à distance. Elle n'existait qu'en un endroit :
     * StoreSaleRequest, c'est-à-dire le formulaire du bureau.
     *
     *   • la SYNCHRO ne la connaissait pas — or c'est le canal des techniciens,
     *     ceux qui vendent réellement sur le terrain ;
     *   • la VALIDATION non plus — le moment où la créance naît, où le solde
     *     bouge et où la marchandise sort.
     *
     * Un client suspendu ou blacklisté pouvait donc être livré à crédit sans
     * plafond, pourvu que la vente ne passe pas par l'écran du bureau.
     *
     * @param float $newCredit Montant qui restera dû après le paiement immédiat.
     */
    public function creditRefusalReason(float $newCredit): ?string
    {
        if ($this->status !== 'actif') {
            return "Le client {$this->name} est {$this->status}.";
        }

        // Plafond à 0 = pas de plafond défini (convention historique du champ),
        // et une vente soldée d'avance ne crée aucun encours.
        if ($newCredit <= 0 || (float) $this->credit_limit <= 0) {
            return null;
        }

        if ((float) $this->balance + $newCredit > (float) $this->credit_limit) {
            return "Plafond crédit dépassé pour {$this->name}. "
                . "Solde actuel : " . number_format((float) $this->balance) . " GNF, "
                . "à créditer : " . number_format($newCredit) . " GNF, "
                . "Plafond : " . number_format((float) $this->credit_limit) . " GNF.";
        }

        return null;
    }

    /**
     * Recalcule le solde client depuis les ventes et paiements.
     *
     * LES DEUX JAMBES DOIVENT PORTER SUR LE MÊME PÉRIMÈTRE.
     *
     * Le débit excluait bien les brouillons et les annulées ; le crédit prenait
     * les paiements de TOUTES les ventes du client, sans filtre. Un acompte
     * encaissé sur une vente restée en brouillon était donc DÉDUIT d'un solde où
     * la vente correspondante n'était jamais ENTRÉE.
     *
     * Ce cas n'a rien de théorique : `CreateSale` crée la vente en `brouillon`
     * puis y attache le paiement immédiat, et ni le formulaire bureau ni la
     * synchro terrain ne valident derrière. La vente reste brouillon, son acompte
     * est enregistré — et `CancelSale` refuse ensuite d'annuler une vente
     * porteuse de paiements, si bien que le brouillon payé ne peut plus être
     * nettoyé et fausse chaque recalcul déclenché par une AUTRE vente du client.
     *
     * Conséquence : solde sous-évalué, crédit disponible sur-évalué — donc de la
     * marchandise qui sort à crédit au-delà du plafond — et solde négatif si le
     * client n'a que ce brouillon.
     *
     * Le périmètre est désormais calculé UNE fois et sert aux deux jambes, comme
     * le fait déjà `Provider::outstandingDebt()`, qui se dit « symétrique de
     * Client::recalculateBalance() » et l'était plus que celle-ci.
     */
    public function recalculateBalance(): void
    {
        $this->update(['balance' => $this->computedBalance()]);
    }

    /**
     * Le solde que le client DEVRAIT avoir, sans rien écrire.
     *
     * Séparé de `recalculateBalance()` pour que la reprise
     * (`clients:repair-balances`) puisse SIMULER : montrer l'écart avant de
     * toucher quoi que ce soit. Une commande qui réécrit des soldes sans
     * pouvoir les annoncer d'abord ne serait pas utilisable en production.
     *
     * C'est la MÊME déclaration qui sert au calcul et à la simulation : recopier
     * la formule dans la commande aurait recréé, à l'endroit exact de la
     * correction, le défaut qu'elle vient réparer.
     */
    public function computedBalance(): float
    {
        /*
         * SANS LA PORTÉE DE FERME AMBIANTE, et délibérément.
         *
         * Ce qu'un client doit ne dépend pas du site que l'opérateur regarde en
         * ce moment. Or `Sale` et `Payment` portent la portée de ferme : calculé
         * depuis un autre site — ou depuis une reprise en lot qui balaie les
         * quatre — le solde retombait à zéro, faute de voir les ventes.
         *
         * Le retrait est sûr : le périmètre reste borné aux ventes DE CE CLIENT,
         * et un client appartient à un seul site. La portée ne pouvait donc rien
         * ajouter ici, seulement retrancher à tort.
         */
        $saleIds = $this->sales()
            ->withoutFarm()
            ->whereNotIn('status', ['annule', 'brouillon'])
            ->pluck('id');

        $totalDue  = (float) Sale::withoutFarm()->whereIn('id', $saleIds)->sum('total_amount');
        $totalPaid = (float) Payment::withoutFarm()->whereIn('sale_id', $saleIds)->sum('amount');

        return round($totalDue - $totalPaid, 2);
    }
}
