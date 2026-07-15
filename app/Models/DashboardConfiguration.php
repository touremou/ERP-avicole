<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personnalisation du tableau de bord PAR utilisateur.
 *
 * Stocke la liste des blocs que l'utilisateur a choisi de masquer. Tout bloc
 * non listé reste visible : ajouter un nouveau bloc au dashboard n'impose donc
 * aucune migration de données (comportement par défaut = tout afficher).
 */
class DashboardConfiguration extends Model
{
    protected $fillable = ['user_id', 'hidden_blocks'];

    protected $casts = ['hidden_blocks' => 'array'];

    /**
     * Catalogue des blocs personnalisables (clé => libellé). Source de vérité
     * partagée par l'IHM de réglage et la vue. L'ordre définit l'affichage IHM.
     *
     * @return array<string, string>
     */
    public const BLOCKS = [
        'priority_alerts' => 'Bandeau d\'alertes critiques',
        'control_center'  => 'Centre de contrôle des alertes',
        'low_stock'       => 'Stocks sous seuil',
        'stock_expiry'    => 'Péremption des consommables',
        'kpi_row'         => 'Indicateurs clés (effectif, mortalité, ponte…)',
        'technical'       => 'Performance technique (zootechnie)',
        'trends'          => 'Tendances 30 jours (graphiques)',
        'financial'       => 'Synthèse financière du mois',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isHidden(string $key): bool
    {
        return in_array($key, $this->hidden_blocks ?? [], true);
    }
}
