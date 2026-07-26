<?php
// ═══════════════════════════════════════════
// app/Models/EmployeeLeave.php
// ═══════════════════════════════════════════

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'employee_id', 'type', 'start_date', 'end_date',
        'days_count', 'status', 'reason', 'approved_by',
        'requested_by', 'approved_at', 'rejection_reason',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }

    /** Congés ayant force de présence (approuvés ou en cours). */
    public function scopeApproved($query)
    {
        return $query->whereIn('status', ['approuve', 'en_cours']);
    }

    /**
     * Congés descendus au terrain (M6) : validés et COURANTS uniquement.
     *
     * Le pointage mobile doit savoir qui est en congé pour ne pas le déclarer
     * présent — comme le pré-remplissage de la grille web. On borne la fenêtre
     * (les congés d'il y a deux ans n'aident personne au rassemblement du
     * matin) et on ne descend AUCUN motif : ni raison, ni type, ni validateur.
     */
    public function scopeCurrentForSync($query)
    {
        return $query->approved()
            ->whereDate('end_date', '>=', now()->subDays(7)->toDateString())
            ->whereDate('start_date', '<=', now()->addDays(60)->toDateString());
    }

    /** Le congé couvre-t-il la date donnée (et est-il validé) ? */
    public function isActiveOn(\Carbon\Carbon $date): bool
    {
        return in_array($this->status, ['approuve', 'en_cours'], true)
            && $date->between($this->start_date, $this->end_date); // inclusif par défaut
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'conge_annuel' => 'Congé annuel',
            'maladie'      => 'Maladie',
            'maternite'    => 'Maternité',
            'sans_solde'   => 'Sans solde',
            'absence'      => 'Absence',
            'formation'    => 'Formation',
            default        => 'Autre',
        };
    }
}
