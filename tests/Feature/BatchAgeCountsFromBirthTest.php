<?php

use App\Models\Batch;
use App\Models\Species;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ÂGE D'UN LOT SE COMPTAIT DEPUIS LA RÉCEPTION, PAS DEPUIS L'ÉCLOSION.
 *
 * Signalé par l'exploitation : « on ne peut saisir que la date d'arrivée. Or un
 * sujet peut être réceptionné à n'importe quel âge. »
 *
 * `Batch::getAgeAttribute()` rendait « jours écoulés depuis arrival_date + 1 ».
 * L'arrivée valait donc jour 1 de vie. Des poulettes prêtes à pondre, reçues à
 * 16 semaines, étaient traitées par toute l'application comme des sujets d'un
 * jour.
 *
 * ─── UNE COLONNE ÉTAIT DÉJÀ LÀ, ET NE SERVAIT À RIEN ───
 *
 * `age_at_arrival` existe en base depuis la migration d'origine, avec un défaut
 * de 1. Elle n'est PAS dans $fillable — le formulaire ne peut pas l'écrire — et
 * AUCUN code ne la lit. Une déclaration sans lecteur ni rédacteur : le besoin
 * avait été vu, le câblage jamais fait.
 *
 * ─── CE QUI ÉTAIT FAUX, ET C'EST LARGE ───
 *
 * Trente points du code lisent `->age`. Pour un lot reçu déjà âgé :
 *
 *   1. LA PONTE EST BLOQUÉE. RecordEggCollection refuse la collecte tant que
 *      l'âge est sous le minimum de ponte : des poulettes en production
 *      auraient été interdites de saisie pendant des mois. Un blocage dur, pas
 *      un chiffre faux ;
 *   2. LES SEUILS DE MORTALITÉ sont ceux du démarrage — les plus tolérants —
 *      donc une mortalité réelle d'adultes passe sous le radar de l'alerte ;
 *   3. LA PHASE D'ALIMENT proposée est « Démarrage » pour un animal adulte ;
 *   4. LA DATE DE FIN PRÉVUE ajoute le cycle entier à l'arrivée ;
 *   5. LE GMQ divise le poids gagné par un âge faux ;
 *   6. LE GUIDE D'ÉLEVAGE tombe sur la mauvaise semaine.
 *
 * ─── LE MODÈLE RETENU : LA DATE DE NAISSANCE ───
 *
 * C'est l'ancrage de tout l'élevage industriel : les guides Cobb, Ross, Lohmann,
 * Hy-Line sont indexés sur l'âge depuis l'éclosion, et c'est la date imprimée sur
 * le bon du couvoir.
 *
 * On la stocke donc plutôt qu'un âge relatif, pour trois raisons :
 *
 *   • elle est INSENSIBLE au délai entre la réception et sa saisie — un âge
 *     saisi trois jours plus tard serait faux de trois jours, une date non ;
 *   • c'est un FAIT sur les animaux, pas sur notre paperasse ;
 *   • elle vaut pour toutes les espèces de l'application — volailles, ruminants,
 *     aquaculture — là où « éclosion » n'en couvre qu'une.
 *
 * ─── RÉTROCOMPATIBILITÉ EXACTE ───
 *
 * La reprise pose `birth_date = arrival_date` pour tous les lots existants (le
 * défaut historique `age_at_arrival = 1` signifie « reçu à un jour »). L'âge
 * affiché de vos lots en cours ne bouge donc PAS d'une journée. Seuls les lots
 * saisis avec une date de naissance antérieure changent de comportement — ce qui
 * est précisément le but.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
});

/** Un lot reçu aujourd'hui, né il y a $ageJours. */
function lotNeIlYA(int $farmId, int $buildingId, int $ageJours): Batch
{
    return Batch::factory()->create([
        'farm_id'          => $farmId,
        'building_id'      => $buildingId,
        'arrival_date'     => today()->toDateString(),
        'birth_date'       => today()->subDays($ageJours - 1)->toDateString(),
        'initial_quantity' => 500,
        'current_quantity' => 500,
        'status'           => 'Actif',
    ]);
}

test('un lot reçu à 16 semaines a bien 112 jours, pas 1', function () {
    /*
     * LE défaut, dans sa forme la plus simple.
     */
    $poulettes = lotNeIlYA($this->farm->id, $this->building->id, 112);

    expect($poulettes->age)->toBe(112);
});

test('la PONTE n’est plus bloquée pour des poulettes déjà en âge', function () {
    /*
     * La conséquence la plus dure : un refus d'enregistrement, pas un chiffre
     * approximatif. Des poulettes en production n'auraient rien pu saisir.
     */
    $poulettes = lotNeIlYA($this->farm->id, $this->building->id, 140);

    expect($poulettes->age)->toBeGreaterThanOrEqual($poulettes->minLayingAgeDays());
});

test('la PHASE de production correspond à l’âge réel', function () {
    // Un sujet de 140 jours n'est pas en phase de démarrage.
    $adulte = lotNeIlYA($this->farm->id, $this->building->id, 140);

    expect($adulte->current_phase)->not->toBe('Démarrage');
});

test('les SEUILS DE MORTALITÉ sont ceux de l’âge réel', function () {
    /*
     * Les seuils de démarrage sont les plus tolérants : les appliquer à des
     * adultes, c'est laisser passer une mortalité anormale sans alerter.
     */
    $jeune  = lotNeIlYA($this->farm->id, $this->building->id, 3);
    $adulte = lotNeIlYA($this->farm->id, $this->building->id, 140);

    expect($jeune->dailyMortalityThreshold())
        ->not->toBe($adulte->dailyMortalityThreshold());
});

test('la DATE DE FIN PRÉVUE se compte depuis la naissance', function () {
    /*
     * Un sujet reçu déjà âgé n'a pas le cycle entier devant lui, mais ce qu'il en
     * reste. Ajouter le cycle à l'ARRIVÉE planifiait son âge de trop.
     *
     * On compare deux lots identiques à la naissance près, plutôt que d'écrire un
     * nombre de jours : l'assertion vaut alors pour toutes les espèces et tous les
     * types de production, dont les cycles vont de 42 à 540 jours.
     */
    $neufJours = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => today()->toDateString(),
        'birth_date'   => today()->toDateString(),
        'status'       => 'Actif',
    ]);

    $dejaAge = Batch::factory()->create([
        'farm_id'           => $this->farm->id,
        'building_id'       => $this->building->id,
        'production_type_id' => $neufJours->production_type_id,
        'species_id'        => $neufJours->species_id,
        'arrival_date'      => today()->toDateString(),
        'birth_date'        => today()->subDays(20)->toDateString(),
        'status'            => 'Actif',
    ]);

    // Reçu 20 jours plus vieux : il finit 20 jours plus tôt, pas au même moment.
    expect((int) $dejaAge->fresh()->expected_end_date
        ->diffInDays($neufJours->fresh()->expected_end_date))->toBe(20);
});

test('la fin prévue ne tombe JAMAIS avant l’arrivée', function () {
    /*
     * La borne du cas limite : un sujet reçu au-delà de son cycle théorique
     * (réforme rachetée, lot repris) ne doit pas produire une date de fin déjà
     * passée, qui ferait clignoter tous les écrans de planning.
     */
    $vieux = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => today()->toDateString(),
        'birth_date'   => today()->subYears(3)->toDateString(),
        'status'       => 'Actif',
    ]);

    expect($vieux->fresh()->expected_end_date->greaterThanOrEqualTo(today()))->toBeTrue();
});

test('SANS date de naissance, rien ne change — la rétrocompatibilité', function () {
    /*
     * LA borne qui protège l'existant. Un lot historique, ou saisi sans la
     * renseigner, garde exactement l'âge d'avant : jours depuis l'arrivée + 1.
     */
    $historique = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => today()->subDays(9)->toDateString(),
        'birth_date'   => null,
        'status'       => 'Actif',
    ]);

    expect($historique->age)->toBe(10);
});

test('un poussin d’un jour reçu le jour de son éclosion a bien 1 jour', function () {
    // La convention conservée : le jour d'éclosion est le jour 1, pas le jour 0.
    $poussins = lotNeIlYA($this->farm->id, $this->building->id, 1);

    expect($poussins->age)->toBe(1);
});

test('le MODÈLE accepte la naissance en écriture de masse', function () {
    /*
     * La colonne `age_at_arrival` existait depuis toujours — mais hors de
     * $fillable, donc inatteignable depuis une écriture de masse. Une colonne
     * qu'on ne peut pas remplir ne vaut pas mieux qu'une colonne absente.
     *
     * ⚠️ CE TEST NE PROUVE QUE $fillable. Il s'est d'abord appelé « le
     * FORMULAIRE web peut enfin l'écrire » — un titre faux, qui a coûté cher :
     * il appelle `update()` SUR LE MODÈLE, court-circuitant CreateBatch et
     * UpdateBatch, qui tous deux jetaient `birth_date` en silence. Le défaut est
     * parti en production et l'exploitation l'a vu avant nous.
     *
     * Le formulaire, lui, est couvert par BirthDateSurvivesTheFormTest, qui
     * passe par les ROUTES et va jusqu'à l'écran.
     */
    $this->actingAs($this->adminUser);

    $lot = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => today()->toDateString(),
        'birth_date'   => today()->toDateString(),
        'status'       => 'Actif',
    ]);

    $lot->update(['birth_date' => today()->subDays(111)->toDateString()]);

    expect($lot->fresh()->age)->toBe(112);
});

test('une naissance APRÈS l’arrivée est refusée', function () {
    /*
     * La borne de cohérence : un sujet ne peut pas arriver avant d'être né. Sans
     * elle, une faute de frappe donnerait un âge négatif à toute l'application.
     */
    $regles = (new \App\Http\Requests\Batch\StoreBatchRequest)->rules();

    expect($regles['birth_date'])->toContain('before_or_equal:arrival_date');
});

test('le TERRAIN peut la transmettre hors ligne', function () {
    /*
     * La mise en lot se fait aussi depuis le mobile. Le champ manquant côté sync
     * aurait rendu la correction inaccessible à ceux qui saisissent réellement.
     */
    Sanctum::actingAs($this->adminUser);
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $reponse = $this->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Str::uuid(),
            'type'    => 'batch.upsert',
            'payload' => [
                'uuid'             => (string) Str::uuid(),
                'code'             => 'LOT-TERRAIN-1',
                'type'             => 'ponte',
                'building_id'      => $this->building->id,
                'initial_quantity' => 500,
                'current_quantity' => 500,
                'arrival_date'     => today()->toDateString(),
                'birth_date'       => today()->subDays(111)->toDateString(),
                'updated_at'       => now()->toISOString(),
            ],
        ]],
    ])->assertOk();

    expect($reponse->json('results.0.status'))->toBe('success')
        ->and(Batch::where('code', 'LOT-TERRAIN-1')->first()->age)->toBe(112);
});
