<?php
// ═══════════════════════════════════════════
// app/Models/EmployeeLeave.php
// ═══════════════════════════════════════════

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\ReferencesEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    /**
     * Statuts qui OCCUPENT le calendrier de l'agent — donc ceux avec lesquels
     * une nouvelle absence entrerait en conflit.
     *
     * Un congé refusé ne compte pas : il n'a jamais eu lieu. Les autres, si —
     * y compris une demande encore en attente, sans quoi on empilerait deux
     * demandes sur les mêmes jours avant que quiconque les regarde.
     */
    public const OCCUPYING_STATUSES = ['demande', 'approuve', 'en_cours', 'termine'];

    /**
     * ABSENCE DÉJÀ ENREGISTRÉE SUR CES MÊMES JOURS — null s'il n'y en a pas.
     *
     * Rien n'empêchait d'enregistrer deux fois la même absence : le responsable
     * de site la saisit, le bureau la ressaisit, ou un sans-solde se superpose à
     * un congé annuel. Et la paie SOMME les congés qui recoupent la période :
     * les mêmes jours étaient alors comptés DEUX FOIS, donc déduits deux fois
     * d'un salaire. Le solde de congés annuels, lui, était décrémenté deux fois
     * à l'approbation.
     *
     * L'agent était sous-payé et son reliquat de congés amputé, sans que rien ne
     * le signale — ni à lui, ni au promoteur.
     */
    public static function overlapping(int $employeeId, string $start, string $end, ?int $exceptId = null): ?self
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::OCCUPYING_STATUSES)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            // Deux intervalles se recoupent si chacun commence avant que
            // l'autre ne finisse. Les bornes sont INCLUSES : deux absences qui
            // partagent une seule journée se chevauchent bel et bien.
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->first();
    }

    use BelongsToFarm, ReferencesEmployee;

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
