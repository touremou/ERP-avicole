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
     */
    public function scopeActiveForSync($query)
    {
        return $query->where('status', 'Actif');
    }

    public function scopeByDepartment($query, $dept)
    {
        return $query->where('department', $dept);
    }

    // --- CONTRAT À DURÉE DÉTERMINÉE ---

    /** Types de contrat qui ont un TERME, donc une décision à prendre. */
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