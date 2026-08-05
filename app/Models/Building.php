<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;

class Building extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    /**
     * Statuts opérationnels d'un bâtiment (valeurs stockées en base dans la
     * colonne `status`). Source unique de vérité référencée par les Actions
     * du module Lots (CreateBatch, CloseBatch, TransferBatch, UpdateBatch),
     * les services et commandes de vide sanitaire, et les vues `buildings/*`.
     *
     * ⚠️ Valeurs historiques (françaises, avec accents) : un renommage
     * casserait les enregistrements existants.
     */
    public const STATUS_VIDE         = 'Vide';
    public const STATUS_DISPONIBLE   = 'Disponible';
    public const STATUS_OCCUPE       = 'Occupé';
    public const STATUS_DESINFECTION = 'En désinfection';
    public const STATUS_MAINTENANCE  = 'Maintenance';

    /**
     * Ensemble des statuts valides — source unique de vérité partagée par les
     * validateurs (Store/UpdateBuildingRequest) afin d'éviter toute dérive
     * entre le modèle et les règles `in:` (le statut Maintenance était défini
     * ici mais absent des validateurs : impossible à enregistrer).
     */
    public const STATUSES = [
        self::STATUS_VIDE,
        self::STATUS_DISPONIBLE,
        self::STATUS_OCCUPE,
        self::STATUS_DESINFECTION,
        self::STATUS_MAINTENANCE,
    ];

    /**
     * Statuts considérés comme « libres » : le bâtiment est prêt à accueillir
     * un nouveau lot (Vide et Disponible sont synonymes côté disponibilité).
     */
    public const STATUS_AVAILABLE = [
        self::STATUS_VIDE,
        self::STATUS_DISPONIBLE,
    ];

    /**
     * Durée standard du vide sanitaire (jours) avant réutilisation.
     *
     * REPLI SEULEMENT. La durée qui gouverne réellement est celle réglée dans
     * Paramètres › Élevage, et se lit par sanitaryBreakDays() — jamais par cette
     * constante en direct.
     */
    public const SANITARY_BREAK_DAYS = 14;

    /**
     * DURÉE DU VIDE SANITAIRE — déclaration UNIQUE.
     *
     * Cette durée était déclarée CINQ fois, de quatre manières qui divergeaient :
     *
     *   • la constante ci-dessus (14 j), utilisée par le compte à rebours affiché
     *     sur la fiche du bâtiment ;
     *   • `elevage.sanitary_break_days` (14 j), lu par le SEUL tableau de bord ;
     *   • `planning.void_sanitaire_days` (21 j) — une AUTRE clef, dans un autre
     *     onglet, portant le même libellé « Durée vide sanitaire », lue par le
     *     service de planning ET par l'écran de création d'un plan de bande ;
     *   • les colonnes `min_sanitary_days` / `max_sanitary_days` (14 / 21),
     *     qu'aucun écran n'a jamais pu écrire — elles ne figurent même pas dans
     *     $fillable — et qui servaient pourtant de repli au planning ;
     *   • la commande planifiée qui LIBÈRE le bâtiment, sur la constante.
     *
     * Conséquence pour l'exploitation : régler « 21 jours » dans Paramètres ›
     * Élevage changeait le décompte du tableau de bord, et RIEN d'autre. Le
     * planning appliquait sa propre clef, le compte à rebours de la fiche
     * affichait 14, et surtout la libération automatique rendait le bâtiment
     * disponible au 14ᵉ jour — une semaine avant le vide demandé.
     *
     * Un vide sanitaire écourté n'est pas un détail de présentation : c'est la
     * mesure qui casse le cycle des pathogènes entre deux bandes. Le réglage
     * existait, l'écran l'acceptait, et le geste ne suivait pas.
     */
    public static function sanitaryBreakDays(): int
    {
        $days = (int) setting('elevage.sanitary_break_days', self::SANITARY_BREAK_DAYS);

        // Un réglage à zéro ou négatif supprimerait le vide sanitaire : on refuse
        // de l'appliquer plutôt que d'obéir à une saisie manifestement erronée.
        return $days > 0 ? $days : self::SANITARY_BREAK_DAYS;
    }

    protected $fillable = [
        'farm_id',
        'name',
        'type',
        'capacity',
        'surface',
        'description',
        'status', // cf. constantes STATUS_* ci-dessus
        'water_source_id', // Source d'eau desservant le bâtiment (citerne…)
        'is_active',
        'disinfection_started_at' // Présent dans votre schéma DB
    ];

    protected $casts = [
        'capacity' => 'integer',
        'surface' => 'decimal:2',
        'is_active' => 'boolean',
        'disinfection_started_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // --- RELATIONS ---

    public function scopePhysical($query)
    {
        return $query->where('name', '!=', 'Zone Fournisseurs Externes');
    }

    /**
     * Un bâtiment contient plusieurs lots (historique et actuel)
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * Source d'eau affectée au bâtiment (citerne / forage / réseau).
     */
    public function waterSource(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WaterSource::class);
    }

    /**
     * Source d'eau EFFECTIVE desservant le bâtiment : la source affectée si
     * définie, sinon la source « par défaut » active de la ferme. Sert à
     * imputer automatiquement la consommation d'eau des lots à la bonne
     * citerne (cf. App\Actions\DailyCheck\SyncWaterConsumption). Retourne null
     * si aucune source affectée ni par défaut n'est disponible.
     */
    public function resolveWaterSource(): ?WaterSource
    {
        if ($this->water_source_id && $this->waterSource) {
            return $this->waterSource;
        }

        return WaterSource::withoutFarm()
            ->where('farm_id', $this->farm_id)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
    }

    // --- SCOPES ---

    /**
     * Bâtiments actuellement en vide sanitaire (désinfection en cours).
     */
    public function scopeInSanitaryBreak($query)
    {
        return $query->where('status', self::STATUS_DESINFECTION);
    }

    // --- LOGIQUE MÉTIER (METHODS) ---

    /**
     * Sécurité : Empêcher le changement de vocation technique si une production est en cours
     */
    public function canChangeType(): bool
    {
        return !$this->batches()->active()->exists();
    }

    /**
     * Disponibilité réelle pour un nouveau lotissement
     */
    public function isAvailable(): bool
    {
        // Un bâtiment n'est disponible que s'il est marqué comme tel ET qu'il n'y a pas de lot actif
        return in_array($this->status, self::STATUS_AVAILABLE, true) && !$this->batches()->active()->exists();
    }

    /**
     * Indique si le bâtiment est en vide sanitaire.
     */
    public function isInSanitaryBreak(): bool
    {
        return $this->status === self::STATUS_DESINFECTION;
    }

    // --- TRANSITIONS D'ÉTAT (centralise la logique dispersée des Actions) ---

    /**
     * Marque le bâtiment comme occupé (un lot actif y est présent).
     */
    public function markOccupied(): void
    {
        $this->update(['status' => self::STATUS_OCCUPE]);
    }

    /**
     * Marque le bâtiment comme disponible (libéré, sans vide sanitaire).
     */
    public function markAvailable(): void
    {
        $this->update(['status' => self::STATUS_DISPONIBLE]);
    }

    /**
     * Déclenche le vide sanitaire : statut « En désinfection » et horodatage
     * du début, utilisé pour calculer le repos restant.
     */
    public function startSanitaryBreak(): void
    {
        $this->update([
            'status'                  => self::STATUS_DESINFECTION,
            'disinfection_started_at' => now(),
        ]);
    }

    /**
     * Temps de repos restant (vide sanitaire), sur la durée RÉGLÉE — et non plus
     * sur la norme codée en dur, qui faisait afficher 14 jours à qui en avait
     * demandé 21.
     */
    public function getSanitaryBreakRemainingDaysAttribute(): int
    {
        if (! $this->isInSanitaryBreak() || !$this->disinfection_started_at) {
            return 0;
        }

        $targetDate = $this->disinfection_started_at->copy()->addDays(self::sanitaryBreakDays());

        // Comparaison de DATES, pas d'instants. L'écart en instants est presque
        // toujours fractionnaire — la désinfection n'a pas commencé à minuit — et
        // sa troncature retirait systématiquement un jour : le dernier jour du
        // vide s'affichait « 0 », donc prêt, alors qu'il courait encore.
        $remaining = now()->startOfDay()->diffInDays($targetDate->startOfDay(), false);

        return $remaining > 0 ? (int) $remaining : 0;
    }

    // --- ACCESSEURS (VIRTUAL ATTRIBUTES) ---

    /**
     * Densité actuelle (Sujets au m²)
     */
    public function getCurrentDensityAttribute(): float
    {
        $activeBatch = $this->batches()->active()->first();
        
        if (!$activeBatch || (float)$this->surface <= 0) {
            return 0.0;
        }

        return round($activeBatch->current_quantity / $this->surface, 2);
    }

    /**
     * Badge de couleur pour l'interface UI (AviSmart Design System)
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_OCCUPE                       => 'orange',
            self::STATUS_DESINFECTION                 => 'purple',
            self::STATUS_DISPONIBLE, self::STATUS_VIDE => 'emerald',
            self::STATUS_MAINTENANCE                  => 'rose',
            default                                   => 'slate',
        };
    }

    /**
     * Taux d'occupation global (%) par rapport à la capacité théorique
     */
    public function getOccupancyRateAttribute(): float
    {
        $activeBatch = $this->batches()->active()->first();
        
        if (!$activeBatch || $this->capacity <= 0) {
            return 0.0;
        }

        return round(($activeBatch->current_quantity / $this->capacity) * 100, 1);
    }
}