<?php

use App\Models\Incubation;
use App\Models\Incubator;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN INCUBATEUR POUVAIT RECEVOIR DEUX FOIS SA CAPACITÉ.
 *
 * La mise à couver bornait le nombre d'œufs par la capacité TOTALE de la machine :
 *
 *     $maxCapacity = $incubator->capacity;   // et non ce qu'il en RESTE
 *
 * Une machine de 10 000 œufs portant déjà un cycle de 8 000 acceptait donc une
 * nouvelle mise à couver de 10 000. Rien ne vérifiait qu'elle n'était pas déjà
 * pleine : `StartIncubation` la marquait « Occupé » sans jamais regarder si elle
 * l'était déjà.
 *
 * ─── POURQUOI ON N'INTERDIT PAS LE SECOND CYCLE ───
 *
 * La provenderie, elle, REFUSE une OP sur une machine occupée — et c'est juste : un
 * mélangeur ne peut pas faire deux gâchées à la fois. Copier cette règle ici serait
 * une erreur : un incubateur accueille couramment plusieurs mises à couver à des
 * dates différentes (incubation multi-étages). Refuser bloquerait une pratique
 * légitime.
 *
 * Ce qu'il faut empêcher, c'est le DÉPASSEMENT. La borne devient donc la place
 * restante, et le message dit ce qu'il y a déjà dedans.
 *
 * ─── TROIS CONSÉQUENCES DE LA MÊME CAUSE ───
 *
 * `activeIncubation()` est un hasOne : avec deux cycles, il en rendait un
 * arbitrairement. D'où :
 *
 *   • le TAUX DE REMPLISSAGE ne comptait qu'un seul cycle — une machine pleine à ras
 *     bord s'affichait à moitié vide ;
 *   • clôturer UN cycle basculait la machine en « Maintenance », et l'abandon en
 *     « Disponible », alors qu'un autre cycle pouvait encore y être en incubation.
 *     L'écran annonçait une machine libre avec des œufs dedans.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->incubateur = Incubator::create([
        'farm_id'  => $this->farm->id,
        'name'     => 'Couveuse 1',
        'capacity' => 10000,
        'status'   => 'Disponible',
    ]);
});

/** Cycle d'incubation en cours, du nombre d'œufs voulu. */
function cycleEnCours(int $farmId, int $incubatorId, int $oeufs, string $code = 'INC-1'): Incubation
{
    // Colonnes NON NULL de la table : batch_id, code_incubation,
    // hatch_date_expected, incubation_duration, chicks_*, coûts.
    $lot = \App\Models\Batch::factory()->create(['farm_id' => $farmId, 'status' => 'Actif']);

    return Incubation::create([
        'farm_id'              => $farmId,
        'incubator_id'         => $incubatorId,
        'batch_id'             => $lot->id,
        'code_incubation'      => $code,
        'start_date'           => now()->subDays(5)->toDateString(),
        'hatch_date_expected'  => now()->addDays(16)->toDateString(),
        'incubation_duration'  => 21,
        'eggs_count'           => $oeufs,
        'chicks_dispatched'    => 0,
        'chicks_remaining'     => 0,
        'egg_unit_cost'        => 0,
        'overhead_cost'        => 0,
        'status'               => 'incubation',
    ]);
}

test('les œufs EN INCUBATION comptent tous les cycles, pas seulement le premier', function () {
    cycleEnCours($this->farm->id, $this->incubateur->id, 4000, 'INC-A');
    cycleEnCours($this->farm->id, $this->incubateur->id, 3000, 'INC-B');

    expect($this->incubateur->fresh()->eggsInIncubation())->toBe(7000)
        ->and($this->incubateur->fresh()->remainingCapacity())->toBe(3000);
});

test('le taux de remplissage reflète la charge RÉELLE', function () {
    // Avant : hasOne → un seul cycle compté. Une machine pleine à 70 % s'affichait
    // à 40 %, et c'est sur ce chiffre qu'on décidait s'il restait de la place.
    cycleEnCours($this->farm->id, $this->incubateur->id, 4000, 'INC-A');
    cycleEnCours($this->farm->id, $this->incubateur->id, 3000, 'INC-B');

    expect($this->incubateur->fresh()->occupancy_rate)->toBe(70.0);
});

test('une mise à couver qui DÉPASSE la place restante est refusée', function () {
    // LE défaut : 8 000 déjà dedans, machine de 10 000, et une mise à couver de
    // 10 000 était acceptée.
    cycleEnCours($this->farm->id, $this->incubateur->id, 8000, 'INC-A');

    $this->actingAs($this->adminUser)
        ->post(route('incubations.store'), [
            'incubator_id' => $this->incubateur->id,
            'start_date'   => now()->toDateString(),
            'eggs_count'   => 10000,
            'source_type'  => 'external',
            'provider_id'  => \App\Models\Provider::factory()->create(['farm_id' => $this->farm->id])->id,
        ])
        ->assertSessionHasErrors('eggs_count');

    expect(Incubation::withoutGlobalScopes()->count())->toBe(1);
});

test('le refus DIT ce qu’il y a déjà dans la machine', function () {
    // « Capacité dépassée » sans dire pourquoi laisse chercher : la machine est
    // pourtant annoncée à 10 000 sur sa fiche.
    cycleEnCours($this->farm->id, $this->incubateur->id, 8000, 'INC-A');

    $this->actingAs($this->adminUser)
        ->post(route('incubations.store'), [
            'incubator_id' => $this->incubateur->id,
            'start_date'   => now()->toDateString(),
            'eggs_count'   => 10000,
            'source_type'  => 'external',
            'provider_id'  => \App\Models\Provider::factory()->create(['farm_id' => $this->farm->id])->id,
        ]);

    $message = (string) (session('errors')['default']['messages']['eggs_count'][0] ?? '');

    expect($message)->toContain('8')       // ce qui est déjà dedans
        ->and($message)->toContain('2');   // ce qu'il reste
});

test('une mise à couver qui TIENT dans la place restante est acceptée', function () {
    // Le pendant : l'incubation multi-étages est une pratique légitime, et la
    // bloquer serait pire que le défaut d'origine.
    cycleEnCours($this->farm->id, $this->incubateur->id, 8000, 'INC-A');

    $this->actingAs($this->adminUser)
        ->post(route('incubations.store'), [
            'incubator_id' => $this->incubateur->id,
            'start_date'   => now()->toDateString(),
            'eggs_count'   => 2000,
            'source_type'  => 'external',
            'provider_id'  => \App\Models\Provider::factory()->create(['farm_id' => $this->farm->id])->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Incubation::withoutGlobalScopes()->count())->toBe(2);
});

test('clôturer UN cycle ne libère pas une machine encore pleine', function () {
    $a = cycleEnCours($this->farm->id, $this->incubateur->id, 4000, 'INC-A');
    cycleEnCours($this->farm->id, $this->incubateur->id, 3000, 'INC-B');

    $a->update(['fertile_eggs' => 3500]);

    app(\App\Actions\Incubation\RecordHatching::class)->execute($a->fresh(), ['hatched_chicks' => 3000]);

    // 3 000 œufs sont encore en incubation : la machine n'est ni en maintenance ni
    // disponible.
    expect($this->incubateur->fresh()->eggsInIncubation())->toBe(3000)
        ->and($this->incubateur->fresh()->status)->toBe('Disponible');
});

test('clôturer le DERNIER cycle envoie bien la machine au nettoyage', function () {
    // Le pendant : une machine réellement vidée doit passer par la désinfection.
    $a = cycleEnCours($this->farm->id, $this->incubateur->id, 4000, 'INC-A');
    $a->update(['fertile_eggs' => 3500]);

    app(\App\Actions\Incubation\RecordHatching::class)->execute($a->fresh(), ['hatched_chicks' => 3000]);

    expect($this->incubateur->fresh()->eggsInIncubation())->toBe(0)
        ->and($this->incubateur->fresh()->status)->toBe('Maintenance');
});

test('abandonner UN cycle ne libère pas une machine encore pleine', function () {
    $a = cycleEnCours($this->farm->id, $this->incubateur->id, 4000, 'INC-A');
    cycleEnCours($this->farm->id, $this->incubateur->id, 3000, 'INC-B');

    $this->incubateur->update(['status' => 'Occupé']);

    app(\App\Actions\Incubation\AbortIncubation::class)->execute($a);

    expect($this->incubateur->fresh()->status)->toBe('Occupé')
        ->and($this->incubateur->fresh()->eggsInIncubation())->toBe(3000);
});
