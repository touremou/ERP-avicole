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
     * NOM DE L'EXPLOITATION — déclaration UNIQUE de l'identité sortante.
     *
     * Ce nom signait TROIS choses par trois chemins différents :
     *
     *   • les documents (relevés client, tickets, bulletins) → general.company_name,
     *     le SEUL réglage réellement déclaré, modifiable dans les Réglages ;
     *   • les rapports cultures → general.farm_name, une clef qui n'a jamais
     *     existé parmi les réglages : ces quatre PDF imprimaient donc toujours
     *     leur texte de repli, « ERP Avicole », quoi qu'on saisisse ;
     *   • les messages WhatsApp → config('whatsapp.farm_name'), c'est-à-dire une
     *     variable d'environnement (.env) INACCESSIBLE depuis l'application.
     *
     * Conséquence, invisible depuis n'importe lequel des trois : le promoteur
     * pouvait saisir le nom de son exploitation dans les Réglages sans que ses
     * alertes cessent de partir signées « AviSmart » sur les téléphones de ses
     * techniciens et de ses clients. Rien ne le lui disait.
     *
     * L'ordre de résolution va du plus intentionnel au plus lointain : ce que
     * l'utilisateur a saisi l'emporte toujours sur ce que le serveur suppose. Le
     * .env reste en dernier recours pour ne pas régresser un site qui l'aurait
     * réglé avant que ce réglage n'existe.
     */
    public static function companyName(): string
    {
        $candidates = [
            static::get('general.company_name'),
            config('whatsapp.farm_name'),
            config('app.name'),
        ];

        foreach ($candidates as $name) {
            if (filled($name)) {
                return (string) $name;
            }
        }

        return 'AviSmart';
    }

    /**
     * IDENTITÉ DE L'ÉMETTEUR — déclaration UNIQUE de qui signe un document.
     *
     * Cette identité était écrite QUATRE fois, dans quatre documents, et chacun
     * n'en imprimait qu'une partie :
     *
     *   • la FACTURE : nom, pays, NIF, RCCM — mais ni adresse ni téléphone ;
     *   • le TICKET de vente : nom, NIF, RCCM ;
     *   • le REÇU de caisse : nom, adresse, téléphone — sans NIF ni RCCM ;
     *   • le BULLETIN de paie : nom, adresse, téléphone.
     *
     * La facture — celle qui part chez le client et qui compte fiscalement — était
     * donc justement celle qui ne portait pas l'adresse de l'émetteur.
     *
     * ─── ET SURTOUT ───
     *
     * Le bloc « Vendeur » de la facture ne lisait RIEN : il imprimait
     * « AviSmart SARL » et « Conakry, République de Guinée » CODÉS EN DUR. Le
     * promoteur pouvait renseigner le nom de son exploitation dans les Réglages —
     * l'en-tête l'affichait — et la partie qui l'identifie légalement continuait
     * de désigner une autre société. Ses factures partaient chez ses clients au nom
     * d'AviSmart SARL, sans que rien ne le lui dise.
     *
     * C'est exactement le défaut corrigé sur companyName() plus tôt dans cet
     * audit — le nom saisi qui n'atteignait pas les messages sortants. Il avait
     * survécu ici, sous une forme pire : non pas un repli mal choisi, mais une
     * valeur en dur qu'aucun réglage ne pouvait atteindre.
     *
     * Les valeurs vides sont rendues telles quelles : à chaque document de décider
     * s'il masque la ligne. Inventer une adresse par défaut redonnerait à une
     * supposition l'autorité d'une mention légale.
     *
     * @return array{name: string, address: string, phone: string, fiscal_id: string,
     *               rccm: string, country: string, logo: string}
     */
    public static function companyIdentity(): array
    {
        return [
            'name'      => static::companyName(),
            'address'   => trim((string) static::get('general.company_address', '')),
            'phone'     => trim((string) static::get('general.company_phone', '')),
            'fiscal_id' => trim((string) static::get('general.fiscal_id', '')),
            'rccm'      => trim((string) static::get('general.rccm', '')),
            'country'   => trim((string) static::get('general.country', 'Guinée')),
            'logo'      => trim((string) static::get('general.company_logo', '')),
        ];
    }

    /**
     * HEURE D'UN RÉGLAGE — déclaration UNIQUE de « ce qu'est une heure valide ».
     *
     * Quatre réglages portent une heure saisie à la main (unité déclarée HH:MM) :
     * l'heure du résumé quotidien, celle du digest d'activité, et les deux bornes
     * des heures ouvrées. Rien ne validait la saisie — l'écran des Réglages
     * enregistre n'importe quelle chaîne — et deux lecteurs en tiraient deux
     * comportements opposés :
     *
     *   • NotificationHub::isAfterHours() rattrape l'échec et désactive la
     *     détection : dégradation propre ;
     *   • routes/console.php passait la valeur BRUTE à dailyAt(), qui la découpe
     *     et la donne au constructeur d'expression cron.
     *
     * Conséquence du second, vérifiée à la main : taper « 25:00 » dans les
     * Réglages fait lever le constructeur cron, et `schedule:run` échoue AVANT
     * d'exécuter quoi que ce soit. Les VINGT-TROIS tâches planifiées s'arrêtent —
     * sauvegardes, alertes de contrat, pointages manquants, péremptions, résumé
     * quotidien — à chaque minute, indéfiniment. Et en silence : la ligne de cron
     * recommandée redirige sa sortie vers /dev/null.
     *
     * Une faute de frappe dans un champ de formulaire arrêtait donc toute
     * l'automatisation de l'exploitation, sans un mot.
     *
     * Les valeurs silencieusement fausses comptent autant que celles qui cassent :
     * un champ VIDE donnait « dans 10 heures », c'est-à-dire minuit — le résumé
     * quotidien partait à 00:00 sur les téléphones. Ici, vide = valeur par défaut.
     *
     * Ce qui est accepté, du plus au moins probable sous les doigts :
     *   '7'  '07'        → 07:00      (heure seule)
     *   '19h30' '19h'    → 19:30 / 19:00  (notation française)
     *   '07:00:00'       → 07:00      (secondes ignorées)
     *   '7:5'            → 07:05      (complété)
     * Tout le reste rend le défaut, et le journal dit lequel et pourquoi.
     */
    public static function hour(string $dotKey, string $default): string
    {
        $raw = trim((string) static::get($dotKey, ''));

        if ($raw === '') {
            return $default;
        }

        $normalized = static::normalizeHour($raw);

        if ($normalized === null) {
            \Illuminate\Support\Facades\Log::warning(
                "[Setting::hour] {$dotKey} = « {$raw} » n’est pas une heure valide ; {$default} appliqué."
            );

            return $default;
        }

        return $normalized;
    }

    /**
     * Rend l'heure normalisée « HH:MM », ou null si la saisie est illisible.
     *
     * Séparée de hour() pour que l'écran des Réglages puisse REFUSER la saisie
     * avec la règle exacte qu'appliquera le planificateur. Sans cela il aurait
     * fallu une seconde définition de « heure valide » — précisément le défaut que
     * ce lot corrige, et qu'on aurait recréé dans le formulaire.
     */
    public static function normalizeHour(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        // « 19h30 », « 19 h 30 », « 19H » → séparateur unifié.
        $candidate = rtrim((string) preg_replace('/\s*[hH]\s*/', ':', $raw), ':');

        $parts = explode(':', $candidate);

        if (! ctype_digit($parts[0] ?? '') || (isset($parts[1]) && ! ctype_digit($parts[1]))) {
            return null;
        }

        $h = (int) $parts[0];
        $m = (int) ($parts[1] ?? 0);

        if ($h > 23 || $m > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $m);
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
    /**
     * Valeur BRUTE, sans cast : la seule façon de distinguer « pas de réglage »
     * de « réglé à zéro ».
     *
     * `castValue` transforme une chaîne vide en 0 pour un réglage numérique —
     * ce qui est le bon comportement pour un seuil, et le mauvais pour une
     * cible : une cible vide veut dire « pas de référence », et l'afficher
     * comme 0 % donnerait à une absence l'autorité d'une mesure.
     */
    public static function rawValue(string $dotKey): ?string
    {
        $value = static::getAllCached()[$dotKey] ?? null;

        return $value === null ? null : (string) $value;
    }

    private static function getSettingMeta(string $dotKey): ?array
    {
        // Mémorisé par le MÊME cache que les valeurs, et non dans une variable
        // statique de processus : figée pour toute la durée du processus, elle
        // rendait le typage d'un réglage dépendant de l'ordre des appels — un
        // réglage ajouté après le premier accès n'était jamais typé.
        // Le cache lui-même peut être indisponible — il vit en base, et les
        // réglages sont lus AVANT que les migrations n'aient tourné.
        try {
            $meta = Cache::get(static::$cacheKey . ':meta');
        } catch (\Throwable $e) {
            $meta = null;
        }

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

            try {
                Cache::put(static::$cacheKey . ':meta', $meta, static::$cacheTtl);
            } catch (\Throwable $e) {
                // Cache indisponible : on relira la table au prochain appel.
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
        Cache::forget(static::$cacheKey . ':meta');
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
            'mail'        => ['label' => 'E-mail (SMTP)', 'icon' => 'fa-envelope',         'color' => 'blue'],
            'sms'         => ['label' => 'SMS',           'icon' => 'fa-comment-sms',      'color' => 'blue'],
            'rh'          => ['label' => 'RH & Paie',     'icon' => 'fa-users',            'color' => 'violet'],
            'stocks'      => ['label' => 'Stocks',        'icon' => 'fa-boxes-stacked',    'color' => 'orange'],
            'ventes'      => ['label' => 'Ventes',        'icon' => 'fa-cash-register',    'color' => 'teal'],
            'cultures'    => ['label' => 'Cultures',      'icon' => 'fa-leaf',             'color' => 'green'],
            'numbering'   => ['label' => 'Numérotation',  'icon' => 'fa-hashtag',          'color' => 'slate'],
            'etiquettes'  => ['label' => 'Étiquettes',    'icon' => 'fa-tag',              'color' => 'purple'],
            'telemetry'   => ['label' => 'Capteurs',      'icon' => 'fa-satellite-dish',   'color' => 'cyan'],
            'licence'     => ['label' => 'Licence',       'icon' => 'fa-key',              'color' => 'purple'],
        ];
    }
}
