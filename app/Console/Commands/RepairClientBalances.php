<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recale les soldes clients faussés par les acomptes sur brouillon.
 *
 * `Client::recalculateBalance()` excluait les brouillons et les annulées du
 * DÉBIT (les ventes) mais pas du CRÉDIT (les paiements). Un acompte encaissé sur
 * une vente restée en brouillon était donc DÉDUIT d'un solde où la vente
 * correspondante n'était jamais ENTRÉE.
 *
 * La correction rend le calcul juste pour l'avenir. Mais `clients.balance` est
 * une colonne STOCKÉE : les soldes déjà écrits gardent leur erreur jusqu'au
 * prochain recalcul du client — lequel n'a lieu qu'à la validation, au paiement,
 * à l'annulation ou au retour d'une de ses ventes. Un client sans mouvement
 * pourrait donc rester faux indéfiniment, et son crédit disponible avec.
 *
 * ─── ELLE EST IDEMPOTENTE ───
 *
 * Elle ne rejoue aucune écriture comptable : elle recalcule le solde depuis les
 * ventes et les paiements — la source de vérité — et n'écrit que là où le
 * stocké diffère. La relancer n'a aucun effet la seconde fois.
 *
 * ─── ELLE COUVRE TOUS LES SITES ───
 *
 * En console il n'y a pas de ferme courante en session : la portée de ferme est
 * alors inerte (cf. BelongsToFarm), donc les clients des quatre sites sont
 * traités en une passe. `--farm=` permet de s'en tenir à un seul.
 *
 * Usage :
 *   php artisan clients:repair-balances              # SIMULATION — rien n'est écrit
 *   php artisan clients:repair-balances --force      # applique les écarts
 *   php artisan clients:repair-balances --farm=2     # un seul site
 *
 * Convention partagée avec eggs:repair-stock, batches:rebuild-quantities,
 * feed:recompute-costs et stocks:sync : une commande qui réécrit des chiffres
 * simule par défaut.
 */
class RepairClientBalances extends Command
{
    protected $signature = 'clients:repair-balances
                            {--force : APPLIQUER les écarts. Sans ce drapeau : simulation seule}
                            {--farm= : Ne traiter que les clients de cette ferme (id)}';

    protected $description = 'Recale les soldes clients faussés par les acomptes sur vente en brouillon';

    public function handle(): int
    {
        $simulation = ! $this->option('force');

        if ($simulation) {
            $this->warn('SIMULATION — aucune écriture. Ajoutez --force pour appliquer.');
        }

        $this->newLine();

        $ecarts = $this->ecartsParClient();

        if ($ecarts->isEmpty()) {
            $this->info('Tous les soldes clients sont justes : aucun écart.');
            return self::SUCCESS;
        }

        $this->table(
            ['Client', 'Site', 'Solde stocké', 'Solde réel', 'Écart'],
            $ecarts->map(fn ($e) => [
                $e['nom'],
                $e['ferme'],
                number_format($e['stocke'], 0, ',', ' '),
                number_format($e['reel'], 0, ',', ' '),
                sprintf('%+s', number_format($e['ecart'], 0, ',', ' ')),
            ])->all()
        );

        /*
         * L'ÉCART VA PRESQUE TOUJOURS DANS LE MÊME SENS, et il faut le dire.
         *
         * Le défaut DÉDUISAIT un acompte jamais crédité : le solde stocké est
         * donc trop BAS, et le client doit en réalité PLUS que ce qui était
         * affiché. La reprise fait donc remonter des créances — ce n'est pas une
         * perte, c'est de l'argent dû qu'on ne voyait pas.
         */
        $sousEvalues = $ecarts->where('ecart', '>', 0);
        $surEvalues  = $ecarts->where('ecart', '<', 0);

        $this->newLine();

        if ($sousEvalues->isNotEmpty()) {
            $this->line(sprintf(
                '%d client(s) doivent PLUS que le solde affiché — total masqué : %s %s.',
                $sousEvalues->count(),
                number_format($sousEvalues->sum('ecart'), 0, ',', ' '),
                config('app.currency', 'GNF'),
            ));
        }

        if ($surEvalues->isNotEmpty()) {
            $this->line(sprintf(
                '%d client(s) doivent MOINS que le solde affiché — total : %s %s.',
                $surEvalues->count(),
                number_format(abs($surEvalues->sum('ecart')), 0, ',', ' '),
                config('app.currency', 'GNF'),
            ));
        }

        if ($simulation) {
            $this->newLine();
            $this->line('Relancez avec --force pour écrire ces soldes.');
            return self::SUCCESS;
        }

        /*
         * On délègue au modèle : la formule ne vit qu'à un seul endroit
         * (Client::computedBalance), et la recopier ici aurait recréé — à
         * l'endroit exact de la correction — le défaut qu'elle répare.
         */
        DB::transaction(function () use ($ecarts) {
            foreach ($ecarts as $e) {
                $e['client']->recalculateBalance();
            }
        });

        $this->newLine();
        $this->info("{$ecarts->count()} solde(s) client recalé(s).");

        return self::SUCCESS;
    }

    /**
     * Écart, par client, entre le solde STOCKÉ et le solde RÉEL.
     *
     * Le solde réel se lit sur les ventes et les paiements — la source de vérité
     * — via la déclaration unique du modèle. Les clients justes sont écartés :
     * la table ne montre que ce qui bouge, sinon la sortie serait illisible sur
     * une base de plusieurs centaines de clients.
     */
    private function ecartsParClient(): \Illuminate\Support\Collection
    {
        /*
         * PORTÉE EXPLICITE, jamais héritée de l'ambiance.
         *
         * En console il n'y a pas de ferme courante, donc la portée de ferme est
         * inerte et tous les sites remontent. Mais s'en remettre à cette absence
         * serait fragile : lancée depuis un contexte qui porte une ferme en
         * session — une file d'attente, un déclenchement web — la reprise ne
         * traiterait qu'UN site et l'annoncerait comme complète.
         *
         * Une reprise silencieusement partielle est pire qu'une reprise refusée :
         * on croit les soldes recalés alors que trois sites sur quatre restent
         * faux. On retire donc la portée, et `--farm` est le SEUL moyen de
         * restreindre — visible dans la commande tapée.
         */
        $requete = Client::withoutFarm()->with('farm:id,name');

        if ($farmId = $this->option('farm')) {
            $requete->where('farm_id', (int) $farmId);
        }

        $ecarts = collect();

        // Par paquets : une exploitation à quatre sites peut porter beaucoup de
        // clients, et chacun déclenche deux agrégats.
        $requete->chunkById(200, function ($clients) use ($ecarts) {
            foreach ($clients as $client) {
                $stocke = round((float) $client->balance, 2);
                $reel   = $client->computedBalance();
                $ecart  = round($reel - $stocke, 2);

                // Tolérance au centime : les décimales stockées ne doivent pas
                // faire apparaître un écart là où il n'y en a pas.
                if (abs($ecart) < 0.01) {
                    continue;
                }

                $ecarts->push([
                    'client' => $client,
                    'nom'    => $client->name,
                    'ferme'  => $client->farm?->name ?? '—',
                    'stocke' => $stocke,
                    'reel'   => $reel,
                    'ecart'  => $ecart,
                ]);
            }
        });

        return $ecarts;
    }
}
