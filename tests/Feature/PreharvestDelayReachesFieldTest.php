<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE REFUS ARRIVAIT APRÈS LE GESTE QU'IL DEVAIT EMPÊCHER.
 *
 * Une récolte sous DÉLAI AVANT RÉCOLTE (résidus phytosanitaires) est refusée
 * DÉFINITIVEMENT par le serveur, des deux côtés — web et synchro. Côté synchro
 * le refus est un « conflict » : non rejouable, direction le bac « À corriger ».
 *
 * Mais la charge utile descendue au terrain ne portait RIEN sur ce délai : le
 * cycle arrivait avec son nom, son code, sa parcelle et sa date de semis, un
 * point c'est tout.
 *
 * Un technicien hors réseau récoltait donc une parcelle sous délai, saisissait
 * sa récolte, et ne l'apprenait qu'à la synchronisation. Deux dégâts d'un coup :
 *
 *   • la saisie est perdue — refus définitif, pas de rejeu ;
 *   • et surtout LA RÉCOLTE A DÉJÀ EU LIEU. Le produit est coupé, avec ses
 *     résidus. Le refus protégeait le registre, plus le consommateur.
 *
 * ─── LA RÈGLE ÉTAIT EXPOSÉE, ET LUE PAR PERSONNE ───
 *
 * `CropCycle::isHarvestBlocked()` existait déjà sur le modèle — déclarée,
 * publique, appelée par AUCUN code de production. Le modèle savait répondre à
 * la question ; rien ne la lui posait.
 *
 * La date descend désormais avec le cycle, par le même mécanisme d'attribut
 * dérivé que `can_collect_eggs` pour les lots d'élevage. L'écran de récolte du
 * mobile avertit et désactive l'enregistrement AVANT que le sécateur ne serve.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($this->managerUser);

    $this->parcelle = Plot::create([
        'farm_id' => $this->farm->id, 'code' => 'P-' . Str::upper(Str::random(4)),
        'name' => 'Parcelle maïs', 'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE,
    ]);
});

/** Cycle en cours, avec ou sans traitement phytosanitaire récent. */
function cycleAvecTraitement(int $farmId, int $plotId, ?int $delaiJours, int $ilYA = 2): CropCycle
{
    $cycle = CropCycle::create([
        'farm_id' => $farmId, 'plot_id' => $plotId,
        'code' => 'MAI-' . Str::upper(Str::random(4)),
        'crop_name' => 'Maïs', 'planting_date' => now()->subMonths(3)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_EN_COURS,
    ]);

    if ($delaiJours !== null) {
        CropInput::create([
            'farm_id' => $farmId, 'crop_cycle_id' => $cycle->id,
            'type' => 'phyto', 'name' => 'Lambda-cyhalothrine',
            'quantity' => 1, 'unit' => 'l', 'total_cost' => 50_000,
            'input_date' => now()->subDays($ilYA)->toDateString(),
            'preharvest_days' => $delaiJours,
        ]);
    }

    return $cycle->fresh();
}

/** Le cycle tel que le terrain le reçoit. */
function cyclePourLeTerrain(int $cycleId): ?array
{
    return collect(
        test()->getJson('/api/v1/sync/pull')->assertOk()->json('entities.crop_cycles.upserts')
    )->firstWhere('id', $cycleId);
}

test('le cycle descend au terrain AVEC la date de fin de délai', function () {
    /*
     * LE défaut : cette information n'existait nulle part dans la charge utile.
     * Traitement il y a 2 jours, délai de 14 jours → récolte permise à J+12.
     */
    $cycle = cycleAvecTraitement($this->farm->id, $this->parcelle->id, 14);

    $recu = cyclePourLeTerrain($cycle->id);

    expect($recu)->not->toBeNull()
        ->and($recu)->toHaveKey('harvest_blocked_until')
        ->and($recu['harvest_blocked_until'])->toBe(now()->addDays(12)->toDateString());
});

test('un cycle SANS délai descend avec un blocage nul', function () {
    // La borne : la très grande majorité des cycles ne sont pas bloqués, et
    // l'écran ne doit rien afficher pour eux.
    $cycle = cycleAvecTraitement($this->farm->id, $this->parcelle->id, null);

    expect(cyclePourLeTerrain($cycle->id)['harvest_blocked_until'])->toBeNull();
});

test('un délai ÉCHU ne bloque plus', function () {
    /*
     * Le délai se lève tout seul à l'échéance — c'est déjà la règle du serveur.
     * L'attribut doit suivre, sinon l'écran interdirait une récolte permise.
     */
    $cycle = cycleAvecTraitement($this->farm->id, $this->parcelle->id, 7, ilYA: 30);

    expect(cyclePourLeTerrain($cycle->id)['harvest_blocked_until'])->toBeNull();
});

test('la date annoncée au terrain est CELLE que le serveur applique', function () {
    /*
     * L'enjeu : si l'avertissement et le refus divergeaient, on remplacerait un
     * défaut par un autre. On confronte l'attribut au refus réel de la synchro.
     */
    $cycle = cycleAvecTraitement($this->farm->id, $this->parcelle->id, 14);

    $annonce = cyclePourLeTerrain($cycle->id)['harvest_blocked_until'];

    $refus = $this->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Str::uuid(),
            'type' => 'harvest.create',
            'payload' => [
                'uuid' => (string) Str::uuid(),
                'crop_cycle_id' => $cycle->id,
                'harvest_date' => now()->toDateString(),
                'quantity' => 100,
                'unit' => 'kg',
            ],
        ]],
    ])->assertOk();

    expect($refus->json('results.0.status'))->toBe('conflict')
        ->and($refus->json('results.0.message'))->toContain(
            \Illuminate\Support\Carbon::parse($annonce)->format('d/m/Y')
        );
});

test('une récolte APRÈS l’échéance est acceptée', function () {
    // On ne ferme pas la récolte, on la date. Sans cette mesure, une garde trop
    // large ferait passer les tests ci-dessus en bloquant tout.
    $cycle = cycleAvecTraitement($this->farm->id, $this->parcelle->id, 7, ilYA: 30);

    $reponse = $this->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Str::uuid(),
            'type' => 'harvest.create',
            'payload' => [
                'uuid' => (string) Str::uuid(),
                'crop_cycle_id' => $cycle->id,
                'harvest_date' => now()->toDateString(),
                'quantity' => 100,
                'unit' => 'kg',
            ],
        ]],
    ])->assertOk();

    expect($reponse->json('results.0.status'))->toBe('success');
});
