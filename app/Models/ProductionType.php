<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionType extends Model
{
    protected $fillable = [
        'species_id','slug','name_fr','metrics_enabled','kpi_primary','cycle_days_default','is_active',
    ];

    protected $casts = [
        'metrics_enabled'  => 'array',
        'is_active'        => 'boolean',
        'cycle_days_default' => 'integer',
    ];

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * « Actif » veut dire UTILISABLE — donc aussi : dont l'espèce est active.
     *
     * Ce scope ne regardait que son propre drapeau. Or désactiver une espèce
     * dans Paramètres › Espèces ne touche pas ses types de production : une
     * ferme qui arrêtait le porc se voyait toujours proposer « Engraissement
     * (Porc) » à la formule d'aliment, au plan de bande et au protocole
     * sanitaire — trois écrans, tous alimentés par ce seul scope.
     *
     * `SpeciesController::toggle` annonce pourtant la règle : désactiver
     * masque les sélecteurs. Elle était appliquée aux lots (qui passent par
     * `Species::active()`) et à personne d'autre.
     *
     * TOUT type a une espèce : `production_types.species_id` est NOT NULL, et
     * `resolveOrCreate` retombe sur la poule quand l'appelant n'en donne pas.
     * Il n'y a donc pas de cas « sans espèce » à ménager ici — la garde que
     * j'avais d'abord écrite pour lui était du code mort, et aucune mutation ne
     * la tuait. `ProductionTypeSchemaTest` verrouille cette hypothèse : si la
     * colonne redevenait nullable, ce scope serait à revoir.
     */
    public function scopeActive($query)
    {
        return $query->where($query->qualifyColumn('is_active'), true)
            ->whereHas('species', fn ($s) => $s->where('is_active', true));
    }

    /**
     * Les types qu'un FORMULAIRE peut offrir, le COURANT compris.
     *
     * Une formule d'aliment rattachée à un type dont l'espèce a été désactivée
     * depuis doit continuer d'afficher CE type quand on rouvre sa fiche : sans
     * lui, le sélecteur retomberait en silence sur un autre, et la formule
     * changerait de destination à la première modification.
     *
     * Le courant se désigne par son ID ou par son SLUG, parce que les deux
     * écrans ne le stockent pas pareil : une formule porte
     * `production_type_id`, un protocole porte le slug dans sa colonne `type`.
     * Demander un id à l'un des deux l'aurait obligé à le résoudre lui-même —
     * c'est-à-dire à réécrire ici une partie de la règle.
     */
    public static function offrables(int|string|null $courant = null)
    {
        $colonne = is_int($courant) ? 'id' : 'slug';

        return static::query()
            ->where(fn ($q) => $q->active()
                ->when($courant !== null && $courant !== '', fn ($w) => $w->orWhere($colonne, $courant)))
            ->with('species')
            ->orderBy('species_id')
            ->get();
    }

    public function tracks(string $metric): bool
    {
        return (bool) ($this->metrics_enabled[$metric] ?? false);
    }

    /**
     * Secteur d'aliment associé à ce type de production (cf.
     * Batch::FEED_PHASES). Source de vérité partagée par les lots (Batch) et
     * les formules de provenderie (Formula).
     *
     * Volaille : « Chair » ou « Ponte » (ponte/repro/reproducteur → Ponte).
     * Autres espèces : Engraissement / Laitière / Reproducteur /
     * Grossissement / Alevinage selon le slug. En l'absence d'espèce, on
     * retombe sur la logique volaille (rétrocompat mono-espèce).
     */
    public function feedSector(): string
    {
        $slug = strtolower((string) $this->slug);

        if ($this->species?->isVolaille() ?? true) {
            return in_array($slug, ['ponte', 'repro', 'reproducteur'], true)
                ? 'Ponte'
                : 'Chair';
        }

        return match ($slug) {
            'laitiere'              => 'Laitière',
            'grossissement'         => 'Grossissement',
            'alevinage'             => 'Alevinage',
            'repro', 'reproducteur' => 'Reproducteur',
            default                 => 'Engraissement',
        };
    }

    /**
     * Retrouve (ou crée) le type de production correspondant à un slug
     * legacy (ex. 'chair', 'ponte') pour l'espèce donnée. À défaut d'espèce,
     * retombe sur « poulet » (rétrocompat lots volaille mono-espèce).
     *
     * Utilisé à l'écriture (création/modification de lot) pour traduire le
     * champ `type` du formulaire en `production_type_id`, désormais source
     * de vérité.
     */
    public static function resolveOrCreate(string $slug, ?int $speciesId): self
    {
        $speciesId ??= Species::where('slug', 'poulet')->value('id');

        return static::firstOrCreate(
            ['species_id' => $speciesId, 'slug' => $slug],
            ['name_fr' => ucfirst($slug), 'is_active' => true]
        );
    }

    public function getKpiLabelAttribute(): string
    {
        return match($this->kpi_primary) {
            'fcr'      => 'Indice de Consommation',
            'hdp'      => 'Taux de Ponte (HDP)',
            'gmq'      => 'Gain Moyen Quotidien',
            'survie'   => 'Taux de Survie',
            'hdp_lait' => 'Production Laitière',
            'gdq'      => 'Gain de poids/jour',
            default    => $this->kpi_primary,
        };
    }
}
