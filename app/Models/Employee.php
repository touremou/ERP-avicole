<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id', 'user_id', 'employee_id', 'last_name', 'first_name', 'gender', 'birth_date',
        'phone', 'email', 'job_title', 'department', 'contract_type',
        'contract_end_date', 'notice_given_at', 'assigned_building_id',
        'hire_date', 'salary', 'emergency_contact_name', 'emergency_contact_phone',
        'photo_path', 'cv_path', 'status', 'annual_leave_balance', 'orange_money_number'
    ];

    protected $casts = [
        'hire_date' => 'date',
        'birth_date' => 'date',
        'contract_end_date' => 'date',
        'notice_given_at' => 'date',
        'salary' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /**
     * LOGIQUE AUTOMATIQUE : Génération d'ID Matricule
     */
    protected static function booted() {
        static::creating(function ($employee) {
            // Rigueur : On s'assure que l'ID n'existe pas déjà même si le count est identique
            if (empty($employee->employee_id)) {
                $count = static::withTrashed()->whereYear('created_at', date('Y'))->count() + 1;
                $employee->employee_id = 'EMP-' . date('Y') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    // --- RELATIONS ---

    /**
     * Un employé peut être responsable de plusieurs lots (bandes)
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    // Dans app/Models/Employee.php, ajouter :

    public function assignedBuilding(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Building::class, 'assigned_building_id');
    }

    public function leaves(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    /**
     * L'employé est-il en congé approuvé à la date donnée ? Sert de garde-fou
     * à l'affectation des tâches (on n'assigne pas un absent) et au calcul de
     * disponibilité du planning.
     */
    public function isOnLeaveOn(\Carbon\Carbon $date): bool
    {
        return $this->leaves()
            ->whereIn('status', ['approuve', 'en_cours'])
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }

    /**
     * Compte de connexion (User) rattaché à cet employé, le cas échéant.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** L'employé dispose-t-il d'un accès actif à l'application ? */
    public function hasActiveAccess(): bool
    {
        return $this->user && $this->user->isActive();
    }

    // --- ACCESSEURS (LOGIQUE MÉTIER) ---

    /**
     * Nom complet formaté (AviSmart Standard)
     */
    public function getNameAttribute(): string
    {
        return strtoupper($this->last_name) . ' ' . ucfirst($this->first_name);
    }

    /**
     * Calcul de l'ancienneté (en années)
     */
    public function getSeniorityAttribute(): int
    {
        return $this->hire_date ? (int) $this->hire_date->diffInYears(now()) : 0;
    }

    /**
     * URL de la photo avec fallback (Avatar par défaut)
     */
    public function getPhotoUrlAttribute(): string
    {
        if ($this->photo_path) {
            return media_url($this->photo_path);
        }

        // REPLI sur l'avatar du compte : même visage, deux champs. Un agent qui a
        // choisi sa photo depuis le mobile apparaissait en silhouette sur sa
        // propre fiche.
        //
        // UNIQUEMENT si la relation est DÉJÀ chargée : cet accesseur est appelé
        // pour chaque ligne des listes d'équipe. Y déclencher une requête ferait
        // un N+1 silencieux sur cinquante employés. Les écrans qui veulent le
        // repli chargent `user` (c'est déjà le cas de la liste du personnel).
        if ($this->relationLoaded('user') && $this->user?->avatar_path) {
            return media_url($this->user->avatar_path);
        }

        // Avatar par défaut selon le genre (SVG inline, pas de dépendance externe)
        return $this->gender === 'F'
            ? asset('images/avatars/female-tech.svg')
            : asset('images/avatars/male-tech.svg');
    }

    /**
     * Statut stylisé pour les composants Blade
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'Actif'    => 'emerald',
            'Congé'    => 'blue',
            'Suspendu' => 'rose',
            default    => 'slate',
        };
    }

    // --- SCOPES ---

    public function scopeActive($query)
    {
        return $query->where('status', 'Actif');
    }

    /**
     * Employés descendus au terrain (M4) : uniquement les ACTIFS — un mobile
     * n'a pas à connaître les sortants, et la liste reste courte.
     *
     * Même règle d'attribution que le web (assignableInCurrentFarm) : sans elle,
     * un agent prêté serait sélectionnable sur l'ordinateur et absent du
     * téléphone. Une divergence entre les deux supports est le pire des cas —
     * le terrain conclut que « le mobile a perdu des employés ».
     */
    public function scopeActiveForSync($query)
    {
        return $query->assignableInCurrentFarm();
    }

    public function scopeByDepartment($query, $dept)
    {
        return $query->where('department', $dept);
    }

    /**
     * VISIBILITÉ RH d'un employé dans la ferme courante — règle UNIQUE.
     *
     * Un employé est visible s'il est rattaché à la ferme courante (farm_id) OU
     * si son COMPTE a reçu l'accès à cette ferme (farm_user) : c'est le cas d'un
     * agent affecté à un autre site pour y travailler.
     *
     * Cette règle vivait en dur dans EmployeeController::index(), tandis que
     * show(), edit() et toutes les routes à paramètre {employee} passaient par
     * le global scope de ferme. Conséquence : un employé de la seconde
     * catégorie apparaissait dans la LISTE mais son ouverture renvoyait 404
     * (« INTROUVABLE » sur /employees/4). Listé sans être ouvrable — le pire
     * des deux mondes, et invisible en relisant l'un ou l'autre fichier seul.
     *
     * La règle est donc ici, et le binding de route la consomme (AppServiceProvider).
     */
    public function scopeVisibleInCurrentFarm($query)
    {
        $farmId = session('current_farm_id');

        // On retire le scope de FERME, et RIEN D'AUTRE. withoutGlobalScopes()
        // emportait aussi SoftDeletes : les employés ARCHIVÉS réapparaissaient
        // dans tous les sélecteurs « Responsable ». Les écrans qui doivent voir
        // les archives (fiche, binding de route) le demandent explicitement par
        // withTrashed().
        $query->withoutGlobalScope(\App\Scopes\FarmScope::class);

        if (! $farmId) {
            return $query; // hors contexte multi-ferme : aucun filtre (cf. FarmScope)
        }

        $accessUserIds = \Illuminate\Support\Facades\DB::table('farm_user')
            ->where('farm_id', $farmId)->pluck('user_id');

        return $query->where(function ($sub) use ($farmId, $accessUserIds) {
            $sub->where('farm_id', $farmId)
                ->orWhereIn('user_id', $accessUserIds);
        });
    }

    /**
     * Employés QUE L'ON PEUT DÉSIGNER dans les opérations de la ferme courante.
     *
     * Même règle que la visibilité de la fiche, restreinte aux actifs. C'est la
     * liste des menus déroulants « Responsable », « Superviseur », « Opérateur »,
     * « Vendeur »…
     *
     * Corrige la seconde moitié du défaut « agent prêté » : après avoir rendu sa
     * fiche visible et ouvrable, il restait ABSENT de tous les sélecteurs, parce
     * qu'ils reposaient sur le global scope de ferme. On le voyait dans
     * l'annuaire du site où il travaille, et on ne pouvait lui attribuer aucune
     * récolte, aucun lot, aucune tâche : visible mais inutilisable.
     *
     * NE CONCERNE PAS la paie ni les agrégats RH (effectif, masse salariale,
     * indicateurs par site) : ceux-là restent strictement bornés à la ferme, car
     * un agent prêté est payé et évalué par son site d'origine. Élargir la paie
     * serait une décision financière, pas une correction d'affichage.
     */
    public function scopeAssignableInCurrentFarm($query)
    {
        return $query->visibleInCurrentFarm()->active();
    }

    // --- CONTRAT À DURÉE DÉTERMINÉE ---

    /** Types de contrat qui ont un TERME, donc une décision à prendre. */
    /**
     * SERVICES DE LA FERME — déclaration unique.
     *
     * Trois services seulement étaient proposés — Élevage, Administration,
     * Logistique — alors que l'exploitation compte des cultures, une provenderie,
     * un abattoir et un comptoir de vente. Un technicien de cultures était donc
     * classé « Élevage / Technique », et le garde-fou qui vérifie qu'une tâche va
     * au bon service ne pouvait rien dire d'utile.
     *
     * Les libellés divergeaient d'ailleurs entre la création (« Élevage /
     * Technique ») et l'édition (« Élevage & Production ») : le même service
     * portait deux noms selon l'écran.
     *
     * Les CLEFS existantes sont conservées telles quelles : les dossiers en base
     * portent « Elevage », « Administration », « Logistique ». Les renommer
     * demanderait une migration de données pour aucun gain.
     */
    public const DEPARTMENTS = [
        'Elevage'        => ['label' => 'Élevage & Production', 'emoji' => '🐔'],
        'Cultures'       => ['label' => 'Cultures & Maraîchage', 'emoji' => '🌱'],
        'Provenderie'    => ['label' => 'Provenderie',          'emoji' => '🌾'],
        'Abattoir'       => ['label' => 'Abattoir & Transformation', 'emoji' => '🔪'],
        'Commerce'       => ['label' => 'Commerce & Caisse',     'emoji' => '🛒'],
        'Logistique'     => ['label' => 'Logistique & Magasin',  'emoji' => '🚚'],
        'Administration' => ['label' => 'Administration & RH',   'emoji' => '📂'],
    ];

    /** Options du menu déroulant : [clef => « emoji Libellé »]. */
    public static function departmentOptions(): array
    {
        $options = [];

        foreach (self::DEPARTMENTS as $key => $meta) {
            $options[$key] = $meta['emoji'] . ' ' . __($meta['label']);
        }

        return $options;
    }

    /** Libellé lisible d'un service, y compris hérité d'anciennes données. */
    public static function departmentLabel(?string $key): string
    {
        if (blank($key)) {
            return '—';
        }

        return isset(self::DEPARTMENTS[$key])
            ? __(self::DEPARTMENTS[$key]['label'])
            : $key;
    }

    public const FIXED_TERM = ['CDD', 'Journalier'];

    public function contractEvents(): HasMany
    {
        return $this->hasMany(EmployeeContractEvent::class)->latest('decided_on');
    }

    public function hasFixedTerm(): bool
    {
        return in_array($this->contract_type, self::FIXED_TERM, true);
    }

    /**
     * Jours restants avant le terme (négatif = terme dépassé). null si le
     * contrat n'a pas de terme.
     */
    public function getDaysUntilContractEndAttribute(): ?int
    {
        if (! $this->contract_end_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->contract_end_date->copy()->startOfDay(), false);
    }

    /**
     * État de la décision à prendre. C'est CE champ que la liste de suivi trie :
     * l'urgence est le dépassement du terme sans acte, pas la proximité.
     *
     *   sans_terme  contrat sans échéance (CDI) ou terme non renseigné
     *   preavis     un préavis a été émis : la décision est prise
     *   expire      le terme est PASSÉ et rien n'a été décidé → requalification
     *   a_decider   le terme approche dans la fenêtre configurée
     *   en_cours    terme lointain
     */
    public function getContractStageAttribute(): string
    {
        if (! $this->hasFixedTerm() || ! $this->contract_end_date) {
            return 'sans_terme';
        }
        if ($this->notice_given_at) {
            return 'preavis';
        }

        $left = $this->days_until_contract_end;
        if ($left < 0) {
            return 'expire';
        }

        return $left <= (int) setting('rh.contract_notice_days', 30) ? 'a_decider' : 'en_cours';
    }

    /**
     * Contrats à terme SANS terme renseigné — les employés déjà en base avant
     * l'introduction de `contract_end_date`.
     *
     * Ils sont le trou le plus dangereux de la liste de suivi : n'ayant pas de
     * date, ils n'apparaissent dans AUCUNE fenêtre d'échéance. Un CDD sans terme
     * en base est invisible, donc jamais décidé — exactement la situation qu'on
     * cherchait à supprimer. D'où un scope dédié, et un écran de régularisation.
     *
     * Aucune date n'est devinée : hire_date + une durée arbitraire produirait un
     * terme faux, et un terme faux est pire qu'un terme absent — il ferme
     * l'alerte en donnant l'illusion que le dossier est en règle.
     */
    public function scopeMissingContractTerm($query)
    {
        return $query->active()
            ->whereIn('contract_type', self::FIXED_TERM)
            ->whereNull('contract_end_date')
            ->orderBy('hire_date');
    }

    /**
     * Contrats à terme dont l'échéance tombe dans les $days jours — ou est déjà
     * dépassée — et pour lesquels AUCUN préavis n'a été émis. Un préavis émis
     * signifie que la décision est prise : le rappeler chaque jour transforme
     * l'alerte en bruit, et une alerte bruyante n'est plus lue.
     */
    public function scopeContractsToDecide($query, ?int $days = null)
    {
        $days = $days ?? (int) setting('rh.contract_notice_days', 30);

        return $query->active()
            ->whereIn('contract_type', self::FIXED_TERM)
            ->whereNotNull('contract_end_date')
            ->whereNull('notice_given_at')
            ->whereDate('contract_end_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('contract_end_date');
    }
}