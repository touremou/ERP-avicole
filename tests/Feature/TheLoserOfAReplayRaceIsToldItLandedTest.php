<?php

use App\Models\Expense;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Services\Sync\SyncService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * DEUX REJEUX SIMULTANÉS : L'UN RÉUSSISSAIT, L'AUTRE RECEVAIT UNE ERREUR SQL.
 *
 * Mesuré par le drill C4 (docs/audit/drills-concurrence.md), deux processus
 * poussant le MÊME lot d'outbox au même instant, sur MySQL/InnoDB, 5 essais :
 *
 *   • l'invariant métier TIENT — jamais un mouvement de stock, un œuf, un franc
 *     comptés deux fois. Les index UNIQUE font leur travail ;
 *   • mais 18 des 20 opérations PERDANTES remontaient une
 *     `UniqueConstraintViolationException` ou une `DeadlockException` BRUTE.
 *
 * `SyncController::push` ravale ces exceptions en statut `error`. Or `error`
 * est le seul statut que le contrat client (docs/mobile/phase-0-spec.md §4.2)
 * ne définit PAS : l'opération reste dans l'outbox, son compteur de tentatives
 * monte — et c'est ce compteur qui l'envoie au bac « À corriger ».
 *
 * Une opération PARFAITEMENT APPLIQUÉE était donc présentée au terrain comme à
 * ressaisir. C'est le défaut que ce dépôt a déjà nommé et corrigé ailleurs, sur
 * un rejeu de prise de tâche : « une opération qui a réussi partait au bac À
 * corriger ». Ici, la cause n'est pas un statut mal choisi mais une exception
 * de base de données qui n'a jamais été traduite.
 *
 * ─── CE QUE LE DÉPÔT AVAIT DÉJÀ TRANCHÉ ───
 *
 * Le drill C2 a posé la règle, pour la trésorerie : « pour le perdant de la
 * course, "déjà comptabilisé" est la bonne réponse, pas une erreur ». Elle
 * valait pour une écriture ; elle vaut pour toutes. On la généralise à la porte
 * d'entrée de la synchro.
 *
 * ─── POURQUOI CE TEST SIMULE, ET CE QUI PROUVE VRAIMENT ───
 *
 * Une course entre deux processus ne se joue pas dans un test mono-processus :
 * c'est le drill qui en fait foi, avant et après. Ce test-ci VERROUILLE le
 * remède — il éprouve que l'exception de course, quelle qu'elle soit, ressort
 * en `already_synced` une fois la ligne du gagnant posée, et qu'une collision
 * qui n'est PAS un rejeu continue de remonter.
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-C4'], ['name' => 'Ferme C4', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $role = Role::firstOrCreate(
        ['name' => 'terrain_c4'],
        ['label' => 'Terrain', 'display_name' => 'Terrain', 'permissions' => []],
    );

    // Dépenses pour la saisie terrain, logistique pour le mouvement de stock
    // qui sert à éprouver l'interblocage hors transaction.
    foreach (['depenses', 'logistique'] as $module) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => Module::where('slug', $module)->value('id')],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true,
             'can_delete' => false, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    $this->agent = User::factory()->create(['role_id' => $role->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->agent->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->agent);
});

/** La dépense telle que le terrain l'a saisie hors-ligne. */
function depenseDeTerrain(string $uuid): array
{
    return [
        'uuid'           => $uuid,
        'category'       => 'transport',
        'label'          => 'Course au marché',
        'amount'         => 300_000,
        'expense_date'   => now()->subDay()->toDateString(),
        'payment_method' => 'especes',
    ];
}

/**
 * LA COURSE, rejouée à l'identique dans un seul processus.
 *
 * Le premier passage perd : l'index unique tranche, l'exception remonte. Puis,
 * pendant que le perdant s'apprête à relire, la ligne du GAGNANT atterrit —
 * exactement ce que la sonde du drill a observé (le survivant de l'interblocage
 * n'avait pas encore committé quand le perdant relisait).
 */
function coursePerdueAuPremierPassage(string $uuid, \Throwable $verdict, int $passagesPerdus = 1): void
{
    $perdus = 0;

    Expense::creating(function () use (&$perdus, $verdict, $passagesPerdus) {
        if ($perdus < $passagesPerdus) {
            $perdus++;
            throw $verdict;
        }
    });

    Event::listen(function (MessageLogged $log) use ($uuid, &$perdus, $passagesPerdus) {
        if (! str_contains($log->message, 'rejeu concurrent')) {
            return;
        }

        // Le gagnant n'atterrit qu'une fois la course entièrement perdue :
        // c'est ce qui rend le NOMBRE de paliers porteur, et pas décoratif.
        if ($perdus < $passagesPerdus) {
            return;
        }

        if (Expense::withoutGlobalScopes()->where('uuid', $uuid)->exists()) {
            return;
        }

        // Le gagnant a écrit sa dépense : une seule, pour un seul montant.
        Expense::create(array_merge(depenseDeTerrain($uuid), [
            'reference' => 'DEP-GAGNANT',
            'status'    => 'en_attente',
            'farm_id'   => session('current_farm_id'),
            'user_id'   => auth()->id(),
        ]));
    });
}

/** L'exception exacte que MySQL rend au perdant d'une course sur l'index uuid. */
function doublonSurIndexUnique(): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException(
        'mysql',
        'insert into `expenses` (`uuid`) values (?)',
        [],
        new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key 'expenses_uuid_unique'"),
    );
}

/**
 * L'autre issue observée au drill, et la plus fréquente : l'interblocage.
 *
 * C'est le visage que Laravel donne à un interblocage survenu dans une
 * transaction IMBRIQUÉE — le cas de tous les handlers qui ouvrent la leur puis
 * appellent une Action qui ouvre la sienne (`ManagesTransactions`).
 */
function interblocageInnoDb(): DeadlockException
{
    return new DeadlockException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 1213);
}

test('le perdant d’une course reçoit « already_synced », plus une erreur', function () {
    /*
     * LE défaut. `error` n'est pas une nuance de vocabulaire : c'est le statut
     * qui garde l'opération dans la file et finit par l'envoyer au bac.
     */
    $uuid = (string) Str::uuid();
    coursePerdueAuPremierPassage($uuid, doublonSurIndexUnique());

    $resultat = app(SyncService::class)->handle('expense.create', depenseDeTerrain($uuid));

    expect($resultat['status'])->toBe('already_synced');
});

test('un INTERBLOCAGE donne la même réponse — c’est l’issue la plus fréquente', function () {
    /*
     * Le drill l'a montré et la sonde l'a confirmé : sur quatre essais, la
     * première exception du perdant était un interblocage, pas un doublon.
     * InnoDB sacrifie l'un des deux avant même que le doublon ne se voie.
     */
    $uuid = (string) Str::uuid();
    coursePerdueAuPremierPassage($uuid, interblocageInnoDb());

    $resultat = app(SyncService::class)->handle('expense.create', depenseDeTerrain($uuid));

    expect($resultat['status'])->toBe('already_synced');
});

test('et il n’existe qu’UNE dépense, pour UN seul montant', function () {
    /*
     * L'invariant qui compte. Le drill le prouve sous vraie concurrence ; ici
     * on verrouille que le rejeu du remède ne crée pas la seconde ligne que
     * toute cette mécanique existe pour empêcher.
     */
    $uuid = (string) Str::uuid();
    coursePerdueAuPremierPassage($uuid, doublonSurIndexUnique());

    app(SyncService::class)->handle('expense.create', depenseDeTerrain($uuid));

    expect(Expense::withoutGlobalScopes()->where('uuid', $uuid)->count())->toBe(1)
        ->and((float) Expense::withoutGlobalScopes()->where('uuid', $uuid)->sum('amount'))->toBe(300_000.0);
});

test('une contrainte durablement violée remonte toujours — la borne', function () {
    /*
     * LA borne, et elle est essentielle : on ne transforme pas une panne en
     * succès. Si la ligne du gagnant n'arrive jamais — parce qu'il n'y avait pas
     * de gagnant, mais une vraie contrainte violée — les passages suivants
     * échouent de la même façon et l'erreur remonte, comme avant.
     */
    $uuid = (string) Str::uuid();

    Expense::creating(fn () => throw doublonSurIndexUnique());

    expect(fn () => app(SyncService::class)->handle('expense.create', depenseDeTerrain($uuid)))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Expense::withoutGlobalScopes()->where('uuid', $uuid)->exists())->toBeFalse();
});

test('une panne ORDINAIRE n’est pas rejouée du tout', function () {
    /*
     * L'autre moitié de la borne. Rejouer n'est légitime que pour une COURSE :
     * une erreur de programmation, une colonne absente, un service indisponible
     * ne deviennent pas moins graves d'être tentés trois fois. Les rejouer
     * retarderait la réponse d'un lot de cinquante opérations sans rien gagner,
     * et rejouerait une Action dont on ne sait pas où elle s'est arrêtée.
     */
    $uuid = (string) Str::uuid();
    $tentatives = 0;

    Expense::creating(function () use (&$tentatives) {
        $tentatives++;
        throw new RuntimeException('Panne ordinaire');
    });

    expect(fn () => app(SyncService::class)->handle('expense.create', depenseDeTerrain($uuid)))
        ->toThrow(RuntimeException::class);

    expect($tentatives)->toBe(1);
});

test('un interblocage de PREMIER NIVEAU est reconnu aussi', function () {
    /*
     * Le SECOND visage de l'interblocage, et il faut aller le chercher.
     *
     * Dans une transaction IMBRIQUÉE, le framework normalise lui-même : il
     * rend une `DeadlockException`. C'est le cas de la plupart des handlers,
     * qui ouvrent leur transaction puis appellent une Action qui ouvre la
     * sienne — et c'est pour cela que les tests précédents ne touchent jamais
     * cette reconnaissance-ci.
     *
     * Hors transaction — une lecture de validation, un handler qui délègue
     * directement — l'interblocage remonte tel quel, en `QueryException` 40001.
     * Ne reconnaître que le premier visage laisserait ces chemins-là sans
     * réponse, et c'est précisément ce que ce test empêche.
     */
    $stock = \App\Models\Stock::create([
        'farm_id' => session('current_farm_id'), 'item_name' => 'Maïs',
        'category' => 'aliment', 'unit' => 'kg',
        'current_quantity' => 100, 'alert_threshold' => 10, 'unit_price' => 5000,
    ]);

    $uuid = (string) Str::uuid();
    $premiereLecture = true;

    // La lecture de validation (`exists:stocks`) précède toute transaction :
    // l'exception y remonte donc SANS être normalisée par le framework.
    DB::listen(function ($requete) use (&$premiereLecture) {
        if ($premiereLecture && str_contains($requete->sql, 'from "stocks"')) {
            $premiereLecture = false;

            throw new QueryException('mysql', $requete->sql, [], new PDOException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
            ));
        }
    });

    $resultat = app(SyncService::class)->handle('stock_movement.create', [
        'uuid' => $uuid, 'stock_id' => $stock->id, 'type' => 'out', 'quantity' => 10,
    ]);

    expect($resultat['status'])->toBe('success')                      // le rejeu a bien eu lieu
        ->and(\App\Models\StockMovement::where('uuid', $uuid)->count())->toBe(1);
});

test('une course perdue DEUX fois de suite trouve quand même sa réponse', function () {
    /*
     * Pourquoi plusieurs paliers, et pas un seul. Le drill l'a mesuré : le
     * perdant d'un interblocage relit une base où le SURVIVANT n'a pas encore
     * committé — il perd alors une seconde fois, sur le doublon cette fois.
     * Un unique rejeu laissait échouer une opération sur trois.
     */
    $uuid = (string) Str::uuid();
    coursePerdueAuPremierPassage($uuid, doublonSurIndexUnique(), passagesPerdus: 2);

    $resultat = app(SyncService::class)->handle('expense.create', depenseDeTerrain($uuid));

    expect($resultat['status'])->toBe('already_synced')
        ->and(Expense::withoutGlobalScopes()->where('uuid', $uuid)->count())->toBe(1);
});

test('un rejeu SÉQUENTIEL répond comme avant — non-régression', function () {
    /*
     * Le cas courant, et de loin : la file repousse une opération déjà passée,
     * sans course. Le gardien d'idempotence la voit du premier coup, et rien de
     * ce qui précède ne doit s'en mêler.
     */
    $uuid = (string) Str::uuid();
    $sync = app(SyncService::class);

    expect($sync->handle('expense.create', depenseDeTerrain($uuid))['status'])->toBe('success')
        ->and($sync->handle('expense.create', depenseDeTerrain($uuid))['status'])->toBe('already_synced')
        ->and(Expense::withoutGlobalScopes()->where('uuid', $uuid)->count())->toBe(1);
});

test('le rejeu est BORNÉ : il ne peut pas tourner indéfiniment', function () {
    /*
     * La garde du remède. Un rejeu sans borne transformerait une contrainte
     * durablement violée en requête qui ne rend jamais la main — sur une porte
     * d'API que le terrain appelle par lots de cinquante.
     */
    $paliers = (new ReflectionClass(SyncService::class))->getConstant('REJEU_PALIERS_MS');

    expect($paliers)->toBeArray()
        ->and(count($paliers))->toBeLessThanOrEqual(5)
        ->and(array_sum($paliers))->toBeLessThanOrEqual(1000);   // ms cumulées
});
