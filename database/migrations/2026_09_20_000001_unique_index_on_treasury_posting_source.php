<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * L'IDEMPOTENCE DE LA TRÉSORERIE N'AVAIT PAS DE DERNIÈRE LIGNE DE DÉFENSE.
 *
 * `TreasuryPostingService::alreadyPosted()` est un `SELECT … EXISTS` nu, joué
 * HORS de la transaction qui écrit l'écriture. En série il suffit ; en
 * parallèle, deux requêtes le passent toutes les deux et postent chacune leur
 * sortie. La clé d'idempotence — `(source_type, source_id)` — ne portait qu'un
 * index SIMPLE.
 *
 * Prouvé par drill deux processus (protocole docs/audit/drills-concurrence.md),
 * MySQL/InnoDB : deux validations simultanées de la MÊME dépense de 300 000 GNF
 * ont produit, sur cinq essais, DEUX écritures et 600 000 GNF sortis à deux
 * reprises. Les trois autres essais ont été sauvés par un interblocage InnoDB —
 * un accident de verrouillage, pas une garde.
 *
 * Le dépôt s'était pourtant donné la règle, plan-audit-360 §B2 : « Chaque
 * idempotence applicative est doublée d'un index UNIQUE (dernière ligne de
 * défense) ». Le test qui la surveille, `DatabaseConstraintGuardTest`, n'énumère
 * que les tables portant une colonne `uuid` — or la clé d'idempotence de
 * `treasury_transactions` n'est pas un uuid. Le garde-fou était structurellement
 * aveugle à ce cas précis.
 *
 * ─── LES DOUBLONS DÉJÀ EN BASE ───
 *
 * Poser l'index sur des données sales échouerait. On répare donc d'abord, et la
 * réparation RECRÉDITE le compte : un doublon avait déplacé le solde une
 * seconde fois, le supprimer sans rendre l'argent laisserait le solde faux.
 * C'est exactement le geste de `TreasuryPostingService::reverseFor()`.
 *
 * On garde la PREMIÈRE écriture de chaque groupe — la plus ancienne est celle
 * qui correspond au geste réel ; les suivantes sont les rejeux.
 *
 * Les mouvements MANUELS (saisie libre, transfert entre comptes) laissent
 * `source_type`/`source_id` à NULL : deux saisies identiques sont deux
 * mouvements distincts par construction, et un index UNIQUE tolère autant de
 * NULL qu'on veut. Ils ne sont donc pas concernés.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reparerLesDoublons();

        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id'], 'treasury_tx_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->dropUnique('treasury_tx_source_unique');
        });
    }

    /**
     * Supprime les écritures en double et REND au compte ce qu'elles lui avaient
     * pris — sans quoi l'index serait posé sur un solde durablement faux.
     */
    private function reparerLesDoublons(): void
    {
        $groupes = DB::table('treasury_transactions')
            ->select('source_type', 'source_id', DB::raw('COUNT(*) as n'))
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('source_type', 'source_id')
            ->having('n', '>', 1)
            ->get();

        foreach ($groupes as $groupe) {
            $ecritures = DB::table('treasury_transactions')
                ->where('source_type', $groupe->source_type)
                ->where('source_id', $groupe->source_id)
                ->orderBy('id')
                ->get();

            // La première reste : c'est le geste réel. Les suivantes sont des rejeux.
            foreach ($ecritures->skip(1) as $rejeu) {
                if ($rejeu->treasury_account_id) {
                    DB::table('treasury_accounts')
                        ->where('id', $rejeu->treasury_account_id)
                        ->update([
                            'current_balance' => DB::raw(
                                'current_balance + ' . ($rejeu->direction === 'in'
                                    ? '-' . (float) $rejeu->amount
                                    : (float) $rejeu->amount)
                            ),
                        ]);
                }

                DB::table('treasury_transactions')->where('id', $rejeu->id)->delete();

                Log::warning(
                    'Trésorerie — écriture en double supprimée et solde recrédité : '
                    . "{$groupe->source_type}#{$groupe->source_id}, écriture #{$rejeu->id}, "
                    . "{$rejeu->direction} {$rejeu->amount}."
                );
            }
        }
    }
};
