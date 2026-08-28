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

        // TOUT DOSSIER PORTE UNE AFFECTATION. Sans cela, un agent créé par un
        // écran, un import ou un seeder n'apparaîtrait nulle part : la
        // visibilité repose désormais sur l'affectation, plus sur la colonne.
        // La règle vit ici pour qu'aucun chemin de création ne puisse l'oublier.
        static::created(function ($employee) {
            if (! $employee->farm_id) {
                return;
            }

            $employee->assignments()->create([
                'farm_id'    => $employee->farm_id,
                'type'       => 'mutation',
                'start_date' => $employee->hire_date ?: $employee->created_at ?: now(),
                'reason'     => __('Rattachement initial'),
            ]);
        });

        // Le dossier peut encore être déplacé directement (import, correction).
        // On garde l'affectation principale ALIGNÉE, sinon la fiche dirait un
        // site et les sélecteurs un autre — la divergence qu'on vient d'éteindre.
        static::updated(function ($employee) {
            if (! $employee->wasChanged('farm_id') || ! $employee->farm_id) {
                return;
            }

            $current = $employee->primaryAssignmentOn();

            if ($current && (int) $current->farm_id === (int) $employee->farm_id) {
                return;
            }

            $current?->update(['end_date' => today()->subDay()]);

            $employee->assignments()->create([
                'farm_id'    => $employee->farm_id,
                'type'       => 'mutation',
                'start_date' => today(),
                'reason'     => __('Changement de site au dossier'),
            ]);
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

    /**
     * Congés de CET employé — quel que soit le site qui les a saisis.
     *
     * Un congé est classé au dossier de l'agent, donc sur son site d'ORIGINE.
     * Filtré par ferme, ce lien rendait l'absence invisible depuis le site
     * d'accueil d'un agent prêté : il y était « disponible » alors qu'il était
     * en congé chez lui, et le garde-fou d'affectation ne pouvait rien dire.
     *
     * Être en congé ne dépend pas de l'endroit d'où on regarde.
     */
    /**
     * Affectations successives de cet agent — l'historique de ses sites.
     *
     * C'est la source de « où travaille-t-il », en remplacement de la déduction
     * implicite qui a coûté une dizaine de correctifs. Non filtrée par ferme :
     * la fiche doit montrer tout le parcours, pas la seule tranche locale.
     */
    public function assignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeAssignment::class)->orderByDesc('start_date');
    }

    /** Affectation principale (celle qui porte le dossier) à une date donnée. */
    public function primaryAssignmentOn($date = null): ?EmployeeAssignment
    {
        return $this->assignments()
            ->where('type', 'mutation')
            ->coveringDate($date ?: today())
            ->first();
    }

    /**
     * MUTER l'agent vers un autre site : le dossier déménage, donc la paie aussi.
     *
     * L'affectation en cours est CLOSE à la veille — jamais supprimée : c'est ce
     * qui permet à une paie de mois à cheval de savoir où il était, jour par
     * jour. Écraser l'historique rendrait la question sans réponse, comme avant.
     */
    public function transferTo(int $farmId, $date = null, ?string $reason = null, ?int $decidedBy = null): EmployeeAssignment
    {
        $start = \Illuminate\Support\Carbon::parse($date ?: today());

        $this->assignments()
            ->where('type', 'mutation')
            ->coveringDate($start)
            ->get()
            ->each(fn ($assignment) => $assignment->update(['end_date' => $start->copy()->subDay()]));

        $assignment = $this->assignments()->create([
            'farm_id'    => $farmId,
            'type'       => 'mutation',
            'start_date' => $start,
            'reason'     => $reason,
            'decided_by' => $decidedBy,
        ]);

        // Le dossier suit. `updated` ne doit pas rouvrir une seconde affectation :
        // elle vient d'être créée ici, et il la reconnaît (même ferme).
        $this->forceFill(['farm_id' => $farmId])->save();

        return $assignment;
    }

    /**
     * METTRE À DISPOSITION : l'agent travaille ailleurs, son dossier ne bouge pas.
     *
     * Une seconde affectation, bornée. Sans terme, un prêt s'oublie et devient
     * une mutation de fait que personne n'a décidée — exactement ce qui s'était
     * produit avec les accès de compte.
     */
    public function lendTo(int $farmId, $start, $end = null, ?string $reason = null, ?int $decidedBy = null): EmployeeAssignment
    {
        return $this->assignments()->create([
            'farm_id'    => $farmId,
            'type'       => 'mise_a_disposition',
            'start_date' => \Illuminate\Support\Carbon::parse($start ?: today()),
            'end_date'   => $end ? \Illuminate\Support\Carbon::parse($end) : null,
            'reason'     => $reason,
            'decided_by' => $decidedBy,
        ]);
    }

    /** Sites où cet agent travaille à une date donnée (mutation + prêts). */
    public function farmIdsOn($date = null): array
    {
        return $this->assignments()
            ->coveringDate($date ?: today())
            ->pluck('farm_id')
            ->unique()
            ->values()
            ->all();
    }

    public function leaves(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeLeave::class)
            ->withoutGlobalScope(\App\Scopes\FarmScope::class);
    }

    /**
     * Pointages de l'agent — D'OÙ QU'ILS AIENT ÉTÉ SAISIS.
     *
     * Même règle que `leaves()`, et pour la même raison : un agent prêté à un
     * autre site y est pointé, et ces pointages portent le `farm_id` du site
     * D'ACCUEIL. Une requête laissée sous le scope de ferme ne les voit pas.
     *
     * La paie lisait justement `EmployeeAttendance` en direct, donc filtrée :
     * les absences constatées sur le site d'accueil n'étaient pas déduites, et
     * l'agent était payé en plein pour des journées où on l'avait noté absent.
     * La ligne d'à côté — celle des congés — évitait déjà ce piège et disait
     * pourquoi ; le pointage l'avait manqué.
     */
    public function attendances(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeAttendance::class)
            ->withoutGlobalScope(\App\Scopes\FarmScope::class);
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
        return $query->visibleInFarm(session('current_farm_id'));
    }

    /**
     * Même règle, pour une ferme DÉSIGNÉE.
     *
     * Les traitements de fond (génération des tâches, commandes planifiées)
     * tournent sans session : ils reçoivent l'identifiant de ferme en argument.
     * Faute de cette variante, ils réécrivaient le filtre à la main — `where
     * farm_id = X` — et retombaient donc dans le défaut que la règle corrige :
     * les agents prêtés disparaissaient de leur vivier.
     */
    public function scopeVisibleInFarm($query, ?int $farmId, $date = null)
    {
        // On retire le scope de FERME, et RIEN D'AUTRE. withoutGlobalScopes()
        // emportait aussi SoftDeletes : les employés ARCHIVÉS réapparaissaient
        // dans tous les sélecteurs « Responsable ». Les écrans qui doivent voir
        // les archives (fiche, binding de route) le demandent explicitement par
        // withTrashed().
        $query->withoutGlobalScope(\App\Scopes\FarmScope::class);

        if (! $farmId) {
            return $query; // hors contexte multi-ferme : aucun filtre (cf. FarmScope)
        }

        // UNE affectation en cours sur ce site, à cette date. Auparavant la règle
        // se déduisait de deux faits sans rapport — le farm_id du dossier et
        // l'accès du COMPTE à une autre ferme — c'est-à-dire d'un effet de bord
        // que personne n'avait décidé. Une affectation, elle, se décide, se date
        // et se termine.
        $day = $date ?: today();

        return $query->whereHas(
            'assignments',
            fn ($sub) => $sub->where('farm_id', $farmId)->coveringDate($day)
        );
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
        return $query->assignableInFarm(session('current_farm_id'));
    }

    /** Même règle, pour une ferme désignée (cf. scopeVisibleInFarm). */
    public function scopeAssignableInFarm($query, ?int $farmId)
    {
        return $query->visibleInFarm($farmId)->active();
    }

    /**
     * Agents encore À L'EFFECTIF de la ferme courante — congés et suspensions
     * COMPRIS. C'est le périmètre des écrans RH d'absence.
     *
     * `assignableInCurrentFarm()` ne retient que les « Actif », ce qui est juste
     * pour désigner quelqu'un à une tâche mais faux ici : approuver un congé fait
     * passer l'agent au statut « Congé », il quittait donc le sélecteur ET la
     * liste. On saisissait une absence et elle disparaissait de l'écran qui
     * venait de l'enregistrer — en paraissant n'avoir rien enregistré.
     *
     * Seuls les départs (« Parti ») et les dossiers archivés sortent.
     */
    public function scopeOnStaffInCurrentFarm($query)
    {
        return $query->visibleInCurrentFarm()->where('status', '!=', 'Parti');
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

    /**
     * Historique des décisions de contrat — D'OÙ QU'ELLES AIENT ÉTÉ PRISES.
     *
     * Même règle que `leaves()` et `attendances()`. Un agent prêté reste
     * visible depuis le site d'accueil (cf. `visibleInFarm`), dont le
     * responsable peut donc décider d'une prolongation ou d'un préavis :
     * l'événement porte alors le `farm_id` de CE site, et le dossier de l'agent
     * — consulté depuis son site d'origine — n'en voyait plus rien.
     *
     * Ce n'est pas un détail d'affichage. La migration qui crée cette table dit
     * ce qu'elle protège : « écraser contract_end_date à chaque prolongation
     * effacerait l'historique : on ne saurait plus qu'un CDD a été prolongé
     * trois fois, ce qui est précisément ce qu'un contrôle regarde ». Une trace
     * conservée mais invisible ne prouve rien de plus qu'une trace absente —
     * et elle est pire, car on la croit là.
     */
    public function contractEvents(): HasMany
    {
        return $this->hasMany(EmployeeContractEvent::class)
            ->withoutGlobalScope(\App\Scopes\FarmScope::class)
            ->latest('decided_on');
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