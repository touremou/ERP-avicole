<?php

use App\Models\Batch;
use App\Models\Incubation;
use App\Models\Incubator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE GESTE QUI CLÔT NE VÉRIFIAIT PAS SI C'ÉTAIT DÉJÀ CLOS.
 *
 * `RecordMirage` refuse un cycle clôturé depuis toujours — « Impossible
 * d'effectuer un mirage sur un cycle clôturé ». `RecordHatching`, l'action qui
 * POSE ce statut, ne vérifiait rien.
 *
 * ─── CE QUE COÛTAIT LA SECONDE ÉCLOSION ───
 *
 * Les deux portes (web et synchro) remettent, après chaque éclosion,
 * `chicks_dispatched` à 0 et `chicks_remaining` au total éclos. Mesuré sur un
 * cycle de 800 poussins dont 600 étaient DÉJÀ partis en dispatch :
 *
 *   avant  →  600 dispatchés, 200 restants
 *   après  →    0 dispatchés, 800 restants
 *
 * Les 600 poussins déjà répartis dans les bâtiments redevenaient « à
 * dispatcher ». Il suffisait d'un retour arrière ou d'un double envoi sur une
 * connexion lente — le geste exact que ce module garde partout ailleurs.
 *
 * ─── ET LE REFUS DU MIRAGE RENVOYAIT UNE ERREUR 500 ───
 *
 * La garde du mirage lève une `DomainException`. La synchro mobile l'attrape et
 * la rend en conflit lisible ; le contrôleur web ne l'attrapait pas. Mirer un
 * cycle clos depuis le bureau donnait donc une PAGE D'ERREUR, là où le terrain
 * recevait une phrase. Même règle, deux restitutions.
 *
 * ─── UNE ÉCRITURE QUI N'ÉCRIVAIT PAS ───
 *
 * `RecordHatching` écrit `finished_at => now()` à la clôture. La colonne
 * n'était pas dans `$fillable` : l'assignation de masse la jetait sans un mot,
 * et le champ restait toujours nul. C'est cette date que le refus affiche
 * désormais — il fallait donc qu'elle existe vraiment.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $machine = Incubator::create([
        'farm_id' => $this->farm->id, 'name' => 'Couveuse 1',
        'capacity' => 5000, 'status' => 'Disponible',
    ]);

    $lot = Batch::factory()->create(['farm_id' => $this->farm->id]);

    $this->cycle = Incubation::create([
        'farm_id' => $this->farm->id,
        'batch_id' => $lot->id,
        'code_incubation' => 'INC-001',
        'incubator_id' => $machine->id,
        'eggs_count' => 1000,
        'start_date' => now()->subDays(21)->toDateString(),
        'hatch_date_expected' => now()->toDateString(),
        'status' => 'incubation',
    ]);
});

/** Mire puis éclôt le cycle, par les vraies portes. */
function mirerEtEclore(Incubation $cycle, int $fertiles = 900, int $poussins = 800): void
{
    test()->post(route('incubations.mirage', $cycle), ['fertile_eggs' => $fertiles]);
    test()->post(route('incubations.hatch', $cycle), ['hatched_chicks' => $poussins]);
}

test('une seconde éclosion est refusée', function () {
    mirerEtEclore($this->cycle);

    $this->post(route('incubations.hatch', $this->cycle), ['hatched_chicks' => 800])
        ->assertRedirect()->assertSessionHas('error');
});

test('le dispatch déjà effectué n’est PAS remis à zéro', function () {
    /*
     * LE défaut, chiffré. 600 poussins sur 800 sont partis dans les bâtiments ;
     * une seconde soumission les faisait réapparaître comme « à dispatcher ».
     */
    mirerEtEclore($this->cycle);
    $this->cycle->fresh()->update(['chicks_dispatched' => 600, 'chicks_remaining' => 200]);

    $this->post(route('incubations.hatch', $this->cycle), ['hatched_chicks' => 800]);

    expect((int) $this->cycle->fresh()->chicks_dispatched)->toBe(600)
        ->and((int) $this->cycle->fresh()->chicks_remaining)->toBe(200);
});

test('le refus dit QUAND le cycle a été clôturé', function () {
    // L'opérateur doit pouvoir distinguer « j'ai cliqué deux fois » d'une
    // éclosion enregistrée la semaine dernière par quelqu'un d'autre.
    mirerEtEclore($this->cycle);

    $this->post(route('incubations.hatch', $this->cycle), ['hatched_chicks' => 800]);

    expect(session('error'))->toContain(now()->format('d/m/Y'));
});

test('la date de clôture est réellement enregistrée', function () {
    /*
     * `finished_at` était écrit par l'action et jeté par l'assignation de
     * masse : une écriture qui n'écrivait pas. Sans elle, le message ci-dessus
     * afficherait un tiret.
     */
    mirerEtEclore($this->cycle);

    expect($this->cycle->fresh()->finished_at)->not->toBeNull();
});

test('la PREMIÈRE éclosion passe et clôt le cycle', function () {
    // La borne : on ferme le doublon, pas le geste.
    mirerEtEclore($this->cycle);

    expect($this->cycle->fresh()->status)->toBe('clos')
        ->and((int) $this->cycle->fresh()->hatched_chicks)->toBe(800)
        ->and((int) $this->cycle->fresh()->chicks_remaining)->toBe(800);
});

test('mirer un cycle clos donne un MESSAGE, pas une erreur 500', function () {
    /*
     * La règle existait et fonctionnait ; c'est sa restitution qui manquait au
     * web. La synchro attrapait déjà la DomainException.
     */
    mirerEtEclore($this->cycle);

    $this->post(route('incubations.mirage', $this->cycle), ['fertile_eggs' => 500])
        ->assertRedirect()->assertSessionHas('error');
});

test('un cycle NON clos se mire normalement', function () {
    // La borne du mirage : le rattrapage d'exception ne doit rien bloquer.
    $this->post(route('incubations.mirage', $this->cycle), ['fertile_eggs' => 900])
        ->assertRedirect();

    expect($this->cycle->fresh()->status)->toBe('mirage_fait')
        ->and((int) $this->cycle->fresh()->fertile_eggs)->toBe(900);
});
