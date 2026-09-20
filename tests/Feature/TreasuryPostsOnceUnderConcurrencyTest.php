<?php

use App\Actions\Expense\ApproveExpense;
use App\Models\Expense;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'IDEMPOTENCE DE LA TRÉSORERIE N'AVAIT PAS DE DERNIÈRE LIGNE DE DÉFENSE.
 *
 * `TreasuryPostingService::alreadyPosted()` est un `SELECT … EXISTS` nu, joué
 * HORS de la transaction qui écrit l'écriture :
 *
 *     if ($this->alreadyPosted($expense)) { return null; }    // ← ici
 *     ...
 *     return $this->service->record(...)                      // ← transaction là
 *
 * En série, la garde suffit. En parallèle, deux requêtes la passent toutes les
 * deux, et chacune poste sa sortie. La clé d'idempotence — `(source_type,
 * source_id)` — ne portait qu'un index SIMPLE.
 *
 * Et la garde du geste, en amont, était du même bois : `ApproveExpense` lisait
 * `status !== 'en_attente'` sans verrou et hors transaction.
 *
 * ─── PROUVÉ PAR DRILL, PAS PAR LECTURE ───
 *
 * Protocole du dépôt (docs/audit/drills-concurrence.md) : deux processus PHP
 * indépendants, MySQL/InnoDB (133 tables vérifiées InnoDB — cf. la trouvaille
 * C1), guettant un fichier-drapeau commun, validant LA MÊME dépense de
 * 300 000 GNF au même instant. Cinq essais :
 *
 *   • 2 essais → DEUX écritures, 600 000 GNF sortis, solde 9 400 000 au lieu
 *     de 9 700 000 ;
 *   • 2 essais → sauvés par un INTERBLOCAGE InnoDB (verrou partagé de clé
 *     étrangère sur le compte, puis mise à jour exclusive du solde). Un
 *     accident de verrouillage, pas une garde — et le perdant reçoit une
 *     `QueryException` brute ;
 *   • 1 essai → `alreadyPosted()` a vu la ligne à temps.
 *
 * Trois issues pour le même geste : la signature d'une garde qui tient en série
 * et lâche en parallèle. C'est exactement C1, C3 et C5, dont le dépôt avait
 * déjà tiré la leçon : « Un verrou ne protège que ce qu'on lit SOUS lui […]
 * verrou → relecture verrouillante → contrôle → écriture, le tout dans la
 * transaction. » La validation de dépense et le marquage « payé » d'un bulletin
 * étaient restés en arrière.
 *
 * ─── LA RÈGLE QUE LE DÉPÔT S'ÉTAIT DONNÉE, ET QUI MANQUAIT ICI ───
 *
 * plan-audit-360 §B2 : « Chaque idempotence applicative est doublée d'un index
 * UNIQUE (dernière ligne de défense) ». Le test qui surveille cette règle,
 * `DatabaseConstraintGuardTest`, n'énumère que les tables portant une colonne
 * `uuid` — or la clé d'idempotence de `treasury_transactions` n'en est pas une.
 * Le garde-fou était STRUCTURELLEMENT AVEUGLE au seul cas qui manquait.
 *
 * ─── CE QUI EST ÉPROUVABLE ICI, ET CE QUI NE L'EST PAS ───
 *
 * Pest est mono-processus : il ne peut pas rejouer la course. Ce fichier
 * éprouve donc ce qui est éprouvable en série — l'index existe et MORD, le
 * verrou sérialise, les gestes séquentiels restent intacts. La course
 * elle-même est prouvée par le drill, et sa contre-preuve est au même endroit :
 * dix essais après correctif, une seule écriture à chaque fois.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->caisse = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse', 'type' => 'caisse',
        'opening_balance' => 10_000_000, 'current_balance' => 10_000_000, 'is_active' => true,
    ]);
});

/** Une dépense espèces en attente de validation. */
function depenseAValider(int $farmId, int $userId, float $montant = 300_000): Expense
{
    return Expense::create([
        'farm_id' => $farmId, 'label' => 'Gasoil', 'amount' => $montant,
        'category' => 'carburant', 'reference' => 'DEP-' . uniqid(),
        'expense_date' => now()->toDateString(), 'status' => 'en_attente',
        'payment_method' => 'especes', 'user_id' => $userId,
    ]);
}

test('la clé d’idempotence de la trésorerie porte un index UNIQUE', function () {
    /*
     * LA dernière ligne de défense, celle que la règle B2 du dépôt réclame et
     * que son garde-fou ne pouvait pas voir. Dérivé du SCHÉMA, pas d'une liste
     * écrite à la main — et interrogé par le schéma plutôt que par PRAGMA, pour
     * que la garantie vaille sur le moteur de PRODUCTION autant que sur celui
     * des tests.
     */
    $uniques = collect(Schema::getIndexes('treasury_transactions'))
        ->filter(fn ($i) => $i['unique'] ?? false)
        ->map(fn ($i) => collect($i['columns'])->sort()->values()->all());

    expect($uniques->contains(['source_id', 'source_type']))->toBeTrue();
});

test('et cet index MORD : deux écritures pour la même pièce sont refusées', function () {
    /*
     * Un index déclaré qui ne refuse rien ne protège rien. On l'éprouve par le
     * geste qu'il doit empêcher — l'insertion que le perdant d'une course
     * tenterait.
     */
    $depense = depenseAValider($this->farm->id, $this->adminUser->id);
    $depense->update(['status' => 'valide']);

    $ecriture = TreasuryTransaction::where('source_id', $depense->id)->sole();

    expect(fn () => TreasuryTransaction::create([
        'farm_id'             => $ecriture->farm_id,
        'treasury_account_id' => $ecriture->treasury_account_id,
        'direction'           => 'out',
        'amount'              => 300_000,
        'transaction_date'    => now()->toDateString(),
        'category'            => 'depense',
        'source_type'         => $ecriture->source_type,
        'source_id'           => $ecriture->source_id,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('les mouvements MANUELS restent libres — non-régression', function () {
    /*
     * LA borne de l'index : un mouvement saisi à la main ne porte pas de pièce
     * (`source_type`/`source_id` à NULL). Deux saisies identiques sont deux
     * mouvements distincts par construction, et l'index doit les laisser
     * passer — sans quoi on aurait échangé un double-comptage contre
     * l'impossibilité de saisir deux fois le même retrait.
     */
    foreach ([1, 2] as $i) {
        TreasuryTransaction::create([
            'farm_id' => $this->farm->id, 'treasury_account_id' => $this->caisse->id,
            'direction' => 'out', 'amount' => 50_000, 'transaction_date' => now()->toDateString(),
            'category' => 'manuel', 'description' => 'Retrait',
        ]);
    }

    expect(TreasuryTransaction::where('category', 'manuel')->count())->toBe(2);
});

test('valider une dépense ne la décaisse qu’une fois', function () {
    // Le cas nominal, qui doit rester intact : une validation, une sortie.
    $depense = depenseAValider($this->farm->id, $this->adminUser->id);

    (new ApproveExpense())->execute($depense);

    expect(TreasuryTransaction::where('source_id', $depense->id)->count())->toBe(1)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(9_700_000.0);
});

test('la seconde validation est refusée, et ne décaisse rien', function () {
    /*
     * La garde séquentielle doit continuer de mordre APRÈS le passage sous
     * verrou : le re-contrôle se fait désormais sur la ligne verrouillée, pas
     * sur l'instance que l'appelant tient en main.
     */
    $depense = depenseAValider($this->farm->id, $this->adminUser->id);

    (new ApproveExpense())->execute($depense);

    expect(fn () => (new ApproveExpense())->execute($depense))
        ->toThrow(RuntimeException::class);

    expect(TreasuryTransaction::where('source_id', $depense->id)->count())->toBe(1)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(9_700_000.0);
});

test('l’instance rendue porte le statut à jour — non-régression', function () {
    /*
     * Le contrôle se fait maintenant sur une relecture verrouillante, donc sur
     * un AUTRE objet que celui passé par l'appelant. Sans remise en phase,
     * l'écran afficherait « en attente » sur une dépense qui vient d'être
     * validée — et le contrôleur bâtit son message de succès sur cette
     * instance.
     */
    $depense = depenseAValider($this->farm->id, $this->adminUser->id);

    $rendue = (new ApproveExpense())->execute($depense);

    expect($rendue->status)->toBe('valide')
        ->and($depense->status)->toBe('valide')
        ->and($rendue->approved_by)->toBe($this->adminUser->id);
});

test('la validation passe bien par une transaction verrouillante', function () {
    /*
     * C'est le VERROU qui sérialise les deux requêtes, et une relecture non
     * verrouillante aurait l'air identique en série. On éprouve donc la
     * mécanique elle-même : la requête de relecture doit porter FOR UPDATE.
     *
     * (Sur sqlite le verrou n'existe pas ; la clause n'est alors pas émise et
     * le test se borne à constater la transaction. Le drill MySQL fait foi
     * pour la sérialisation réelle.)
     */
    $depense = depenseAValider($this->farm->id, $this->adminUser->id);

    $requetes = [];
    DB::listen(function ($q) use (&$requetes) { $requetes[] = $q->sql; });

    (new ApproveExpense())->execute($depense);

    $relecture = collect($requetes)->first(fn ($sql) => str_contains($sql, 'select')
        && str_contains($sql, 'expenses'));

    expect($relecture)->not->toBeNull();

    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect(strtolower($relecture))->toContain('for update');
    }
});
