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
        'hire_date', 'salary', 'emergency_contact_name', 'emergency_contact_phone',
        'photo_path', 'cv_path', 'status', 'annual_leave_balance', 'orange_money_number'
    ];

    protected $casts = [
        'hire_date' => 'date',
        'birth_date' => 'date',
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

    public function scopeByDepartment($query, $dept)
    {
        return $query->where('department', $dept);
    }
}