<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\BatchCostAllocation;
use App\Models\Employee;
use App\Models\GrazingSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ENREGISTRER UNE SORTIE AU PÂTURAGE — et imputer le temps du berger au lot.
 *
 * Seule porte d'écriture : terrain (SyncService `grazing.create`) et bureau
 * (BatchNaturalFeedingController) — la même règle des deux côtés.
 *
 *   • ruminants seulement (GrazingSession::FAMILLES), lot actif ;
 *   • retour après le départ, au plus 16 h dehors ;
 *   • têtes sorties ≤ effectif du lot, têtes rentrées ≤ têtes sorties ;
 *   • temps du berger : toute la sortie s'il garde, sinon le temps de sortir
 *     et de ramener les bêtes (saisi, 30 min par défaut) ;
 *   • coût = temps du berger × son coût horaire, FIGÉ, imputé au lot.
 *
 * Une tête manquante au retour n'est PAS une mortalité présumée : on alerte
 * l'élevage, qui la retrouve ou la déclare (pointage, incident). Idempotent
 * par uuid.
 */
final class RecordGrazing
{
    /** Temps du berger par défaut en autonomie : sortir puis ramener les bêtes. */
    public const MINUTES_AUTONOMIE_PAR_DEFAUT = 30;

    /**
     * @param  array{uuid?: string, batch_id: int, left_at: string, returned_at: string, mode: string,
     *               heads_out: int, heads_back: int, herder_minutes?: ?int, plot_id?: ?int,
     *               zone?: ?string, notes?: ?string}  $data
     */
    public function execute(array $data, User $auteur): GrazingSession
    {
        if (! empty($data['uuid']) && $deja = GrazingSession::where('uuid', $data['uuid'])->first()) {
            return $deja;
        }

        $lot = Batch::with('species')->findOrFail($data['batch_id']);
        $depart = Carbon::parse($data['left_at']);
        $retour = Carbon::parse($data['returned_at']);
        $duree = (int) $depart->diffInMinutes($retour, false);
        $sorties = (int) $data['heads_out'];
        $rentrees = (int) $data['heads_back'];

        $erreurs = [];
        if (! $lot->isActive()) {
            $erreurs['batch_id'] = __('Le lot :code n’est plus actif.', ['code' => $lot->code]);
        } elseif (! $lot->acceptePaturage()) {
            $erreurs['batch_id'] = __('Le pâturage est réservé aux ruminants.');
        }
        if ($duree <= 0) {
            $erreurs['returned_at'] = __('L’heure de retour doit suivre l’heure de départ.');
        } elseif ($duree > 16 * 60) {
            $erreurs['returned_at'] = __('Une sortie dure au plus 16 heures.');
        }
        if ($sorties < 1 || $sorties > (int) $lot->current_quantity) {
            $erreurs['heads_out'] = __('Têtes sorties : entre 1 et l’effectif du lot (:n).', ['n' => $lot->current_quantity]);
        }
        if ($rentrees > $sorties) {
            $erreurs['heads_back'] = __('Plus de têtes rentrées que de têtes sorties.');
        }
        if (! in_array($data['mode'], array_keys(GrazingSession::MODES), true)) {
            $erreurs['mode'] = __('Mode de sortie inconnu.');
        }
        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        $berger = $data['mode'] === GrazingSession::MODE_GARDE
            ? $duree
            : (int) ($data['herder_minutes'] ?? self::MINUTES_AUTONOMIE_PAR_DEFAUT);
        $berger = min($berger, $duree);

        /** @var Employee|null $employe */
        $employe = $auteur->employee;
        $taux = $employe?->coutHoraire() ?? 0.0;
        $cout = round($berger / 60 * $taux, 2);

        $sortie = DB::transaction(function () use ($data, $auteur, $employe, $lot, $depart, $retour, $duree, $sorties, $rentrees, $berger, $taux, $cout) {
            $sortie = GrazingSession::create([
                'uuid'             => $data['uuid'] ?? null,
                'farm_id'          => $lot->farm_id,
                'batch_id'         => $lot->id,
                'user_id'          => $auteur->id,
                'employee_id'      => $employe?->id,
                'plot_id'          => $data['plot_id'] ?? null,
                'zone'             => filled($data['zone'] ?? null) ? trim($data['zone']) : null,
                'left_at'          => $depart,
                'returned_at'      => $retour,
                'duration_minutes' => $duree,
                'mode'             => $data['mode'],
                'heads_out'        => $sorties,
                'heads_back'       => $rentrees,
                'herder_minutes'   => $berger,
                'hourly_cost_gnf'  => $taux,
                'labor_cost_gnf'   => $cout,
                'notes'            => $data['notes'] ?? null,
            ]);

            if ($cout > 0) {
                $nom = $employe ? trim($employe->first_name . ' ' . $employe->last_name) : $auteur->name;
                BatchCostAllocation::create([
                    'farm_id'      => $lot->farm_id,
                    'batch_id'     => $lot->id,
                    'category'     => BatchCostAllocation::MAIN_OEUVRE_ALIMENTATION,
                    'label'        => "Main-d'œuvre : Pâturage ({$sortie->lieu}) - {$nom}",
                    'amount'       => $cout,
                    'allocated_on' => $depart->toDateString(),
                    'source_type'  => $sortie->getMorphClass(),
                    'source_id'    => $sortie->id,
                ]);
            }

            return $sortie;
        });

        if ($sortie->manquants > 0) {
            rescue(fn () => app(\App\Services\NotificationHub::class)->alertGrazingMissing($sortie));
        }

        return $sortie;
    }
}
