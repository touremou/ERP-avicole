<?php

namespace App\Console\Commands;

use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CONTRÔLE ET RECALE LES SOLDES DE TRÉSORERIE.
 *
 * `treasury_accounts.current_balance` est une colonne STOCKÉE, mouvementée
 * incrément par incrément à chaque écriture. Le grand-livre, lui, est la source
 * de vérité : `opening_balance + Σ entrées − Σ sorties`. Les deux ne peuvent
 * diverger que par accident — et trois accidents sont connus et corrigés :
 *
 *   • le décaissement en double d'un achat d'aliment rectifié (#370) ;
 *   • les deux formules contraires de la caisse (#371) ;
 *   • le double posting sous concurrence (#372).
 *
 * Les correctifs valent pour l'AVENIR. Les soldes déjà écrits, eux, gardent
 * leur erreur : rien ne les recalcule spontanément. Cette commande est le moyen
 * de vérifier, et de recaler.
 *
 * ─── TROIS CONTRÔLES, DANS CET ORDRE ───
 *
 * 1. ÉCRITURES ORPHELINES — une écriture dont la pièce d'origine n'existe plus.
 *    C'est la trace exacte du défaut #370 : `$invoice->payments()->delete()`
 *    supprimait le règlement sans émettre d'événement, la contre-passation ne
 *    partait pas, et l'écriture restait — pointant une ligne détruite, portant
 *    une sortie d'argent qui n'a eu lieu qu'une fois.
 *
 *    La migration de l'index UNIQUE (#372) ne les voit PAS : une orpheline a
 *    une clé unique, c'est un doublon de FAIT, pas de clé. Il faut les chercher
 *    pour les trouver.
 *
 *    On les supprime. Le solde, lui, est recalculé par le contrôle n°3 depuis
 *    le grand-livre assaini — d'où l'ordre : rendre la main ici ferait deux
 *    fois le même travail, et masquerait un incrément erroné derrière un
 *    recalcul juste.
 *
 * 2. DOUBLONS DE CLÉ — deux écritures pour la même pièce. Impossibles depuis
 *    l'index UNIQUE ; on les signale quand même, parce qu'une base non encore
 *    migrée en porte, et parce qu'un contrôle qui ne vérifie que ce qu'on
 *    croit déjà vrai ne sert à rien.
 *
 * 3. DÉRIVE DE SOLDE — le stocké contre le recalculé. Dernier, pour que le
 *    recalcul tombe sur un grand-livre déjà assaini.
 *
 * ─── ELLE EST IDEMPOTENTE, ET SIMULE PAR DÉFAUT ───
 *
 * Elle ne rejoue aucune écriture : elle contre-passe ce qui n'a plus de pièce
 * et recalcule depuis le grand-livre. La relancer n'a aucun effet la seconde
 * fois.
 *
 * Convention partagée avec clients:repair-balances, eggs:repair-stock,
 * batches:rebuild-quantities, feed:recompute-costs et stocks:sync : une
 * commande qui réécrit des chiffres SIMULE par défaut.
 *
 * Usage :
 *   php artisan treasury:repair-balances            # RAPPORT — rien n'est écrit
 *   php artisan treasury:repair-balances --force    # applique les corrections
 *   php artisan treasury:repair-balances --farm=2   # un seul site
 */
class RepairTreasuryBalances extends Command
{
    protected $signature = 'treasury:repair-balances
                            {--force : APPLIQUER les corrections. Sans ce drapeau : rapport seul}
                            {--farm= : Ne traiter que les comptes de cette ferme (id)}';

    protected $description = 'Contrôle les soldes de trésorerie contre le grand-livre et les recale';

    public function handle(): int
    {
        $simulation = ! $this->option('force');

        if ($simulation) {
            $this->warn('RAPPORT SEUL — aucune écriture. Ajoutez --force pour appliquer.');
        }

        $this->newLine();

        $orphelines = $this->traiterLesOrphelines($simulation);
        $doublons   = $this->signalerLesDoublons();
        $derives    = $this->traiterLesDerives($simulation);

        $this->newLine();
        $this->line('─────────────────────────────────────────────');
        $this->line("Écritures orphelines  : {$orphelines}");
        $this->line("Doublons de clé       : {$doublons}");
        $this->line("Soldes dérivés        : {$derives}");

        /*
         * UNE TRACE, POUR QUE LE CONTRÔLE PLANIFIÉ SERVE À QUELQUE CHOSE.
         *
         * En passe planifiée, la sortie console ne va nulle part : un contrôle
         * qui n'écrit que sur un terminal que personne ne regarde ne vaut pas
         * mieux que pas de contrôle. On laisse donc un avertissement au journal
         * dès qu'il y a quelque chose — c'est ce que la surveillance lit.
         */
        if ($orphelines || $doublons || $derives) {
            \Illuminate\Support\Facades\Log::warning(
                'Trésorerie — incohérences détectées : '
                . "{$orphelines} écriture(s) orpheline(s), {$doublons} doublon(s) de clé, "
                . "{$derives} solde(s) dérivé(s)."
                . ($simulation ? ' Aucune correction appliquée (rapport seul).' : ' Corrigées.')
            );
        }

        if ($simulation && ($orphelines || $doublons || $derives)) {
            $this->newLine();
            $this->warn('Relancez avec --force pour appliquer.');
        }

        if (! $simulation && ! $orphelines && ! $doublons && ! $derives) {
            $this->info('Trésorerie cohérente : rien à corriger.');
        }

        return self::SUCCESS;
    }

    /**
     * Une écriture dont la pièce d'origine a disparu porte un mouvement d'argent
     * qui n'a pas eu lieu. On la contre-passe : rendre, puis supprimer.
     */
    private function traiterLesOrphelines(bool $simulation): int
    {
        $traitees = 0;

        // On groupe par type de pièce : chaque morph a sa propre table.
        $types = TreasuryTransaction::query()
            ->whereNotNull('source_type')->whereNotNull('source_id')
            ->distinct()->pluck('source_type');

        foreach ($types as $type) {
            $classe = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? $type;

            if (! class_exists($classe)) {
                $this->warn("Type de pièce inconnu, ignoré : {$type}");
                continue;
            }

            $ecritures = $this->requeteComptes(TreasuryTransaction::where('source_type', $type))->get();

            // Les identifiants encore vivants, en UNE requête — sans quoi on
            // interrogerait la base une fois par écriture.
            $vivants = $classe::withoutGlobalScopes()
                ->whereIn((new $classe)->getKeyName(), $ecritures->pluck('source_id')->unique())
                ->pluck((new $classe)->getKeyName())
                ->flip();

            foreach ($ecritures as $ecriture) {
                if ($vivants->has($ecriture->source_id)) {
                    continue;
                }

                $this->line(sprintf(
                    '  ORPHELINE  écriture #%d  %s %s  → pièce %s#%d disparue',
                    $ecriture->id,
                    $ecriture->direction,
                    number_format((float) $ecriture->amount, 0, ',', ' '),
                    class_basename($classe),
                    $ecriture->source_id
                ));

                $traitees++;

                if ($simulation) {
                    continue;
                }

                /*
                 * ON SUPPRIME SEULEMENT — le solde est l'affaire du recalcul.
                 *
                 * `reverseFor()` rend le delta puis supprime, parce qu'il agit
                 * seul. Ici le contrôle de dérive suit, et il recalcule le solde
                 * DEPUIS le grand-livre : rendre la main ici ferait deux fois le
                 * même travail, et un incrément qui se tromperait passerait
                 * inaperçu derrière un recalcul qui, lui, est juste.
                 *
                 * C'est ce qui rend l'ORDRE porteur : assainir d'abord, compter
                 * ensuite. L'inverse figerait l'orpheline dans le solde.
                 */
                $ecriture->delete();
            }
        }

        return $traitees;
    }

    /** Deux écritures pour la même pièce : impossible depuis l'index UNIQUE, vérifié quand même. */
    private function signalerLesDoublons(): int
    {
        $groupes = $this->requeteComptes(
            TreasuryTransaction::query()->whereNotNull('source_type')->whereNotNull('source_id')
        )
            ->select('source_type', 'source_id', DB::raw('COUNT(*) as n'))
            ->groupBy('source_type', 'source_id')
            ->having('n', '>', 1)
            ->get();

        foreach ($groupes as $groupe) {
            $this->error(sprintf(
                '  DOUBLON    %s#%d porte %d écritures — index UNIQUE absent ou migration non appliquée.',
                class_basename($groupe->source_type),
                $groupe->source_id,
                $groupe->n
            ));
        }

        return $groupes->count();
    }

    /** Le solde stocké contre le grand-livre, compte par compte. */
    private function traiterLesDerives(bool $simulation): int
    {
        $comptes = TreasuryAccount::query()
            ->when($this->option('farm'), fn ($q, $farm) => $q->where('farm_id', $farm))
            ->orderBy('name')->get();

        $derives = 0;

        foreach ($comptes as $compte) {
            $in  = (float) $compte->transactions()->where('direction', 'in')->sum('amount');
            $out = (float) $compte->transactions()->where('direction', 'out')->sum('amount');

            $attendu = round((float) $compte->opening_balance + $in - $out, 2);
            $stocke  = round((float) $compte->current_balance, 2);
            $ecart   = round($stocke - $attendu, 2);

            if (abs($ecart) < 0.01) {
                continue;
            }

            $this->line(sprintf(
                '  DÉRIVE     « %s » : stocké %s, grand-livre %s, écart %s',
                $compte->name,
                number_format($stocke, 0, ',', ' '),
                number_format($attendu, 0, ',', ' '),
                number_format($ecart, 0, ',', ' ')
            ));

            $derives++;

            if (! $simulation) {
                $compte->recomputeBalance();
            }
        }

        return $derives;
    }

    /**
     * Restreint à une ferme si demandé.
     *
     * En console il n'y a pas de ferme courante en session : la portée de ferme
     * est alors inerte (cf. BelongsToFarm), donc les quatre sites sont traités
     * en une passe. `--farm=` permet de s'en tenir à un seul.
     */
    private function requeteComptes($query)
    {
        return $query->when($this->option('farm'), fn ($q, $farm) => $q->where('farm_id', $farm));
    }
}
