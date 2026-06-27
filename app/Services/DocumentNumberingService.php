<?php

namespace App\Services;

use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * Numérotation centralisée et configurable des documents.
 *
 * Chaque type de document (vente, achat, dépense, abattage, production…) avait
 * son propre générateur, avec préfixe et format codés en dur — et la
 * provenderie produisait un numéro horodaté + aléatoire (OP-…-XYZ4), donc non
 * monotone et imprévisible (anti-standard ERP).
 *
 * Ce service est la source de vérité unique. Le PRÉFIXE de chaque type est lu
 * dans les Réglages (groupe « Numérotation »), ce qui le rend configurable sans
 * redéploiement ; le FORMAT (présence de l'année, largeur de séquence) est figé
 * par type pour garantir l'unicité et la continuité des séquences existantes.
 *
 * Formats :
 *   - avec année : PREFIX-AAAA-NNNNNN  (séquence remise à 1 chaque année)
 *   - sans année : PREFIX-NNNNN        (séquence continue)
 *
 * La séquence est dérivée du MAX réel de la colonne (zéro-paddée → l'ordre
 * lexicographique = ordre numérique), sur l'ensemble des fermes et y compris
 * les enregistrements soft-deleted, pour ne jamais réémettre une référence.
 */
class DocumentNumberingService
{
    /**
     * Définition des schémas de numérotation par type de document.
     *
     * @return array<string, array{model:class-string, column:string, prefix_key:string, prefix_default:string, year:bool, pad:int}>
     */
    public static function schemes(): array
    {
        return [
            // Ventes (préfixes historiques dans le groupe « ventes »).
            'sale_bl'             => ['model' => \App\Models\Sale::class,               'column' => 'reference',    'prefix_key' => 'ventes.invoice_prefix_bl',          'prefix_default' => 'BL',    'year' => true,  'pad' => 6],
            'sale_invoice'        => ['model' => \App\Models\Sale::class,               'column' => 'reference',    'prefix_key' => 'ventes.invoice_prefix_tva',         'prefix_default' => 'FAC',   'year' => true,  'pad' => 6],
            // Vente comptant (POS) : ticket de caisse, distinct du BL/facture.
            'sale_pos'            => ['model' => \App\Models\Sale::class,               'column' => 'reference',    'prefix_key' => 'ventes.invoice_prefix_pos',         'prefix_default' => 'TKT',   'year' => true,  'pad' => 6],

            // Documents migrés vers le groupe « numbering ».
            'sale_return'         => ['model' => \App\Models\SaleReturn::class,         'column' => 'reference',    'prefix_key' => 'numbering.sale_return_prefix',      'prefix_default' => 'RET',   'year' => false, 'pad' => 5],
            'supplier_invoice'    => ['model' => \App\Models\SupplierInvoice::class,    'column' => 'reference',    'prefix_key' => 'numbering.supplier_invoice_prefix', 'prefix_default' => 'ACH',   'year' => false, 'pad' => 5],
            'expense'             => ['model' => \App\Models\Expense::class,            'column' => 'reference',    'prefix_key' => 'numbering.expense_prefix',          'prefix_default' => 'DEP',   'year' => false, 'pad' => 5],
            'fuel_expense'        => ['model' => \App\Models\Expense::class,            'column' => 'reference',    'prefix_key' => 'numbering.fuel_prefix',             'prefix_default' => 'GAS',   'year' => false, 'pad' => 5],
            'stock_adjustment'    => ['model' => \App\Models\StockAdjustment::class,    'column' => 'reference',    'prefix_key' => 'numbering.stock_adjustment_prefix', 'prefix_default' => 'AJ',    'year' => false, 'pad' => 5],
            'slaughter_order'     => ['model' => \App\Models\SlaughterOrder::class,     'column' => 'order_number', 'prefix_key' => 'numbering.slaughter_prefix',        'prefix_default' => 'ABA',   'year' => true,  'pad' => 6],
            'transformation'      => ['model' => \App\Models\Transformation::class,     'column' => 'batch_number', 'prefix_key' => 'numbering.transformation_prefix',   'prefix_default' => 'TRANS', 'year' => true,  'pad' => 6],
            'crop_transformation' => ['model' => \App\Models\CropTransformation::class, 'column' => 'batch_number', 'prefix_key' => 'numbering.crop_transformation_prefix', 'prefix_default' => 'TRV', 'year' => true, 'pad' => 6],
            'mill_production'     => ['model' => \App\Models\MillProduction::class,     'column' => 'batch_number', 'prefix_key' => 'numbering.mill_prefix',             'prefix_default' => 'OP',    'year' => true,  'pad' => 6],
        ];
    }

    /**
     * Génère la prochaine référence pour un type de document.
     */
    public static function generate(string $type): string
    {
        $scheme = self::schemes()[$type]
            ?? throw new InvalidArgumentException("Type de document inconnu : {$type}");

        $prefix = trim((string) setting($scheme['prefix_key'], $scheme['prefix_default'])) ?: $scheme['prefix_default'];
        $pad    = $scheme['pad'];
        $year   = now()->format('Y');
        $column = $scheme['column'];

        $like = $scheme['year'] ? "{$prefix}-{$year}-%" : "{$prefix}-%";

        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $scheme['model']::query()->withoutGlobalScopes();

        // Inclure les enregistrements supprimés : une référence consommée ne
        // doit jamais être réattribuée, même après suppression logique.
        if (in_array(SoftDeletes::class, class_uses_recursive($scheme['model']), true)) {
            $query->withTrashed();
        }

        $last = $query->where($column, 'LIKE', $like)->max($column);

        $sequence = $last ? ((int) substr($last, -$pad)) + 1 : 1;

        return $scheme['year']
            ? sprintf("%s-%s-%0{$pad}d", $prefix, $year, $sequence)
            : sprintf("%s-%0{$pad}d", $prefix, $sequence);
    }
}
