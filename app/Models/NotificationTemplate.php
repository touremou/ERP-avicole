<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Modèle de message de notification ÉDITABLE.
 *
 * Les messages WhatsApp/SMS étaient codés en dur dans NotificationHub. Ce
 * modèle permet de les personnaliser sans redéploiement : chaque message porte
 * une clé, et NotificationHub demande le corps via self::bodyFor($key). Si
 * aucun modèle actif n'existe en base, on retombe sur le défaut livré (CATALOG),
 * ce qui garantit qu'une notification part toujours, même sans personnalisation.
 *
 * Sécurité : le rendu se fait par simple substitution de variables {{ clé }}
 * (PAS de Blade/eval), de sorte qu'un texte saisi par un administrateur ne peut
 * jamais exécuter de code.
 */
class NotificationTemplate extends Model
{
    protected $fillable = ['key', 'channel', 'label', 'body', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    private const CACHE_KEY = 'avismart_notif_templates';

    /**
     * Catalogue des messages personnalisables : défaut livré + variables
     * disponibles (pour l'aide à l'édition). Source de vérité unique.
     *
     * @return array<string, array{label:string, variables:array<string>, default:string}>
     */
    public static function catalog(): array
    {
        return [
            'alert_mortality' => [
                'label'     => 'Alerte mortalité (cumul)',
                'variables' => ['emoji', 'batch_code', 'building', 'deaths', 'rate', 'remaining'],
                'default'   => "{{emoji}} *ALERTE MORTALITÉ*\n\n"
                    . "Lot : *{{batch_code}}*\n"
                    . "Bâtiment : {{building}}\n"
                    . "Morts : *{{deaths}}* ({{rate}}%)\n"
                    . "Effectif restant : {{remaining}}\n\n"
                    . "Action requise immédiatement.",
            ],
            'daily_mortality_spike' => [
                'label'     => 'Pic de mortalité du jour',
                'variables' => ['batch_code', 'building', 'deaths', 'daily_rate', 'remaining'],
                'default'   => "🚨 *PIC DE MORTALITÉ — {{building}}*\n\n"
                    . "Lot : *{{batch_code}}*\n"
                    . "Bâtiment : {{building}}\n"
                    . "Morts AUJOURD'HUI : *{{deaths}}* ({{daily_rate}}% de l'effectif)\n"
                    . "Effectif restant : {{remaining}}\n\n"
                    . "Mortalité quotidienne ANORMALE : vérifier maladie, eau, température, intoxication. Isoler les sujets atteints et appeler le vétérinaire si besoin.",
            ],
            'alert_stock' => [
                'label'     => 'Rupture de stock',
                'variables' => ['item_name', 'category', 'quantity', 'unit', 'threshold'],
                'default'   => "🔴 *RUPTURE STOCK*\n\n"
                    . "Article : *{{item_name}}*\n"
                    . "Catégorie : {{category}}\n"
                    . "Restant : *{{quantity}} {{unit}}*\n"
                    . "Seuil alerte : {{threshold}} {{unit}}\n\n"
                    . "Commander immédiatement.",
            ],
            'alert_fuel' => [
                'label'     => 'Carburant critique',
                'variables' => ['source', 'autonomy', 'level', 'capacity'],
                'default'   => "⛽ *CARBURANT CRITIQUE*\n\n"
                    . "Groupe : *{{source}}*\n"
                    . "Autonomie : *{{autonomy}}*\n"
                    . "Niveau cuve : {{level}}L / {{capacity}}L\n\n"
                    . "Commander du carburant AUJOURD'HUI.",
            ],
            'sale_created' => [
                'label'     => 'Nouvelle vente',
                'variables' => ['header', 'reference', 'client', 'total', 'status', 'flags'],
                'default'   => "{{header}}\n\n"
                    . "Réf : *{{reference}}*\n"
                    . "Client : {{client}}\n"
                    . "Total : *{{total}} GNF*\n"
                    . "Statut : {{status}}{{flags}}",
            ],
            'stock_expiry' => [
                'label'     => 'Péremption de consommables',
                'variables' => ['farm', 'count', 'items'],
                'default'   => "⏳ *PÉREMPTION CONSOMMABLES — {{farm}}*\n\n"
                    . "{{count}} article(s) à surveiller :\n"
                    . "{{items}}\n\n"
                    . "Vérifier et retirer du circuit les lots périmés.",
            ],
            'payment_received' => [
                'label'     => 'Paiement reçu',
                'variables' => ['header', 'amount', 'method', 'reference', 'client', 'remaining', 'flags'],
                'default'   => "{{header}}\n\n"
                    . "Montant : *{{amount}} GNF*\n"
                    . "Mode : {{method}}\n"
                    . "Vente : {{reference}}\n"
                    . "Client : {{client}}\n"
                    . "Reste dû : {{remaining}} GNF{{flags}}",
            ],
        ];
    }

    /**
     * Corps d'un message : modèle actif en base, sinon défaut livré.
     */
    public static function bodyFor(string $key): string
    {
        $overrides = Cache::rememberForever(self::CACHE_KEY, function () {
            return static::where('is_active', true)->pluck('body', 'key')->all();
        });

        if (! empty($overrides[$key])) {
            return $overrides[$key];
        }

        return static::catalog()[$key]['default'] ?? '';
    }

    /**
     * Substitue les variables {{ clé }} (tolérant aux espaces) par leurs valeurs.
     */
    public static function interpolate(string $body, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '';
        }, $body);
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::clearCache());
        static::deleted(fn () => static::clearCache());
    }
}
