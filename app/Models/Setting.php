<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    protected $fillable = [
        'group', 'key', 'value', 'type', 'label', 'description',
        'options', 'unit', 'display_order', 'is_sensitive', 'farm_id',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
    ];

    // ─── CACHE ───

    private static string $cacheKey = 'avismart_settings';
    private static int $cacheTtl = 3600; // 1 heure

    /**
     * Récupère une valeur de paramètre.
     *
     * Usage :
     *   Setting::get('elevage.cycle_chair', 42)
     *   setting('general.tva_rate', 18)  // via le helper global
     */
    public static function get(string $dotKey, $default = null)
    {
        $all = static::getAllCached();

        $value = $all[$dotKey] ?? $default;

        // Cast automatique
        $setting = static::getSettingMeta($dotKey);
        if ($setting) {
            return static::castValue($value, $setting['type']);
        }

        return $value;
    }

    /**
     * Définit une valeur de paramètre.
     */
    public static function set(string $dotKey, $value): void
    {
        [$group, $key] = explode('.', $dotKey, 2);

        static::updateOrCreate(
            ['group' => $group, 'key' => $key, 'farm_id' => null],
            ['value' => (string) $value]
        );

        static::clearCache();
    }

    /**
     * Récupère tous les paramètres d'un groupe.
     */
    public static function getGroup(string $group): array
    {
        $all = static::getAllCached();
        $result = [];

        foreach ($all as $dotKey => $value) {
            if (str_starts_with($dotKey, "{$group}.")) {
                $key = str_replace("{$group}.", '', $dotKey);
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Charge tous les paramètres avec cache.
     *
     * Robustesse : si le store de cache (table `cache` en driver
     * "database") n'existe pas encore — ex: tout premier `php artisan
     * migrate` sur une base vide, avant que la migration du cache ne
     * passe — Cache::remember() lèverait une QueryException. On retombe
     * alors sur une lecture directe sans cache.
     */
    public static function getAllCached(): array
    {
        $resolve = function () {
            try {
                if (! Schema::hasTable('settings')) return [];

                return static::whereNull('farm_id')
                    ->get()
                    ->mapWithKeys(fn($s) => ["{$s->group}.{$s->key}" => $s->value])
                    ->toArray();
            } catch (\Throwable $e) {
                return [];
            }
        };

        try {
            return Cache::remember(static::$cacheKey, static::$cacheTtl, $resolve);
        } catch (\Throwable $e) {
            return $resolve();
        }
    }

    /**
     * Récupère les métadonnées d'un paramètre (type, options, etc.).
     */
    private static function getSettingMeta(string $dotKey): ?array
    {
        static $meta = null;

        if ($meta === null) {
            try {
                if (! Schema::hasTable('settings')) return null;
                $meta = static::whereNull('farm_id')
                    ->get()
                    ->mapWithKeys(fn($s) => [
                        "{$s->group}.{$s->key}" => [
                            'type' => $s->type,
                            'options' => $s->options,
                        ]
                    ])
                    ->toArray();
            } catch (\Throwable $e) {
                $meta = [];
            }
        }

        return $meta[$dotKey] ?? null;
    }

    /**
     * Cast la valeur selon le type défini.
     */
    private static function castValue($value, string $type)
    {
        if ($value === null || $value === '') return $type === 'number' ? 0 : $value;

        return match($type) {
            'number'  => is_numeric($value) ? (str_contains($value, '.') ? (float) $value : (int) $value) : 0,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($value, true) ?? [],
            default   => (string) $value,
        };
    }

    /**
     * Vide le cache des paramètres.
     */
    public static function clearCache(): void
    {
        Cache::forget(static::$cacheKey);
    }

    /**
     * Liste des groupes avec labels pour l'IHM.
     */
    public static function getGroups(): array
    {
        return [
            'general'     => ['label' => 'Général',       'icon' => 'fa-building',        'color' => 'slate'],
            'elevage'     => ['label' => 'Élevage',       'icon' => 'fa-dove',             'color' => 'blue'],
            'production'  => ['label' => 'Production',    'icon' => 'fa-egg',              'color' => 'amber'],
            'pisciculture'=> ['label' => 'Pisciculture',  'icon' => 'fa-water',            'color' => 'green'],
            'provenderie' => ['label' => 'Provenderie',   'icon' => 'fa-wheat-awn',        'color' => 'lime'],
            'abattoir'    => ['label' => 'Abattoir',      'icon' => 'fa-drumstick-bite',   'color' => 'rose'],
            'couvoir'     => ['label' => 'Couvoir',       'icon' => 'fa-temperature-half', 'color' => 'pink'],
            'planning'    => ['label' => 'Planning',      'icon' => 'fa-calendar-days',    'color' => 'indigo'],
            'energie'     => ['label' => 'Énergie',       'icon' => 'fa-bolt',             'color' => 'cyan'],
            'whatsapp'    => ['label' => 'WhatsApp',      'icon' => 'fa-bell',             'color' => 'emerald'],
            'rh'          => ['label' => 'RH & Paie',     'icon' => 'fa-users',            'color' => 'violet'],
            'stocks'      => ['label' => 'Stocks',        'icon' => 'fa-boxes-stacked',    'color' => 'orange'],
            'ventes'      => ['label' => 'Ventes',        'icon' => 'fa-cash-register',    'color' => 'teal'],
            'cultures'    => ['label' => 'Cultures',      'icon' => 'fa-leaf',             'color' => 'green'],
            'numbering'   => ['label' => 'Numérotation',  'icon' => 'fa-hashtag',          'color' => 'slate'],
            'etiquettes'  => ['label' => 'Étiquettes',    'icon' => 'fa-tag',              'color' => 'purple'],
        ];
    }
}
