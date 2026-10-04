<?php

use App\Models\Farm;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA PAGE DIT AU MOTEUR HORS-LIGNE QUI EST CONNECTÉ, ET SUR QUEL SITE.
 *
 * Le moteur web (resources/js/sync-engine.js) marque chaque saisie de son
 * auteur et de son site, et ne pousse que celles du compte connecté sur le site
 * actif. Sans quoi, sur un navigateur partagé, la dépense du magasinier partait
 * sous la session du comptable connecté après lui — rejoué en navigateur réel :
 * enregistrée au nom du second compte.
 *
 * Les décisions sont éprouvées par `node --test` (resources/js/tests). Ce qui
 * se garde ICI, c'est leur source : si ces balises disparaissent, le moteur ne
 * sait plus qui est connecté et ne pousse plus rien.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('la page porte le compte connecté et le site actif', function () {
    $html = $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, '<meta name="avismart-user" content="'.$this->adminUser->id.'">'))->toBeTrue('auteur absent');
    expect(str_contains($html, '<meta name="avismart-farm" content="'.$this->farm->id.'">'))->toBeTrue('site absent');
});

test('après bascule de site, la page porte le NOUVEAU site', function () {
    $autre = Farm::create(['name' => 'Site de Kindia', 'code' => 'KIN']);
    foreach ([$this->farm->id, $autre->id] as $farmId) {
        DB::table('farm_user')->insertOrIgnore(['user_id' => $this->adminUser->id, 'farm_id' => $farmId]);
    }

    $html = $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $autre->id])
        ->get(route('dashboard'))
        ->getContent();

    expect(str_contains($html, '<meta name="avismart-farm" content="'.$autre->id.'">'))->toBeTrue('site actif non reflété');
});
