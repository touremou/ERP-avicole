<?php

use App\Models\FinishedProduct;
use App\Models\FinishedProductAdjustment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE STOCK LE PLUS CHER ÉTAIT LE SEUL QU'ON POUVAIT RÉDUIRE EN SILENCE.
 *
 * Deux écrans réécrivent à la main la quantité d'un produit fini :
 *
 *   • l'AJUSTEMENT d'inventaire ;
 *   • la DESTRUCTION (péremption, saisie sanitaire), qui met la ligne à ZÉRO d'un
 *     seul geste.
 *
 * Les deux étaient bien TRACÉS — journal en base ET fichier — mais n'alertaient
 * personne. Or l'ajustement du magasin ordinaire alerte depuis toujours, et le lot
 * #230 a étendu cette règle à ses deux autres chemins.
 *
 * La viande — le stock de plus grande valeur au kilo — restait donc le seul endroit
 * où l'on pouvait effacer de la quantité sans que rien ne parte. Le promoteur, à
 * l'étranger, ne l'apprenait qu'en ouvrant une table que personne n'ouvre.
 *
 * ─── DEUX SÉVÉRITÉS, ET UNE EXCEPTION ───
 *
 * L'ajustement suit la règle habituelle : critique à la baisse, informatif à la
 * hausse (cf. #229, #230). Une DESTRUCTION est critique quelle que soit la
 * quantité — elle supprime la totalité d'une ligne, c'est le mouvement le plus lourd
 * de tout le magasin.
 *
 * ─── CE QU'ON NE FAIT PAS ───
 *
 * On n'entrave ni l'un ni l'autre. Détruire un lot périmé est une obligation
 * sanitaire, pas un geste suspect : on le rend visible, on ne le bride pas. Et
 * l'alerte ne bloque jamais l'écriture — perdre la correction d'inventaire serait
 * pire que perdre l'alerte.
 */

beforeEach(function () {
    $this->setUpRbac();

    // L'état réel de l'exploitation : le WhatsApp ne sort pas (cf. #216).
    Setting::set('whatsapp.driver', 'log');

    $this->produit = FinishedProduct::create([
        'farm_id'             => $this->farm->id,
        'product_name'        => 'Poulet entier congelé',
        'product_type'        => 'volaille',
        'current_quantity_kg' => 120,
        'unit'                => 'kg',
        'unit_price'          => 45000,
        'unit_cost'           => 30000,
        'alert_threshold_kg'  => 10,
    ]);
});

/** Texte lisible de la dernière notification (le JSON échappe accents ET barres). */
function finishedAlertText(int $userId): string
{
    $raw = DB::table('notifications')->where('notifiable_id', $userId)->latest('created_at')->value('data');

    return json_encode(json_decode((string) $raw, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
}

test('une DIMINUTION d’inventaire alerte, et en critique', function () {
    // 40 kg de viande qui disparaissent d'un inventaire : c'est le signal.
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.finished.adjust', $this->produit), [
            'new_quantity_kg' => 80,
            'reason'          => 'Inventaire physique du 15/08',
        ])->assertRedirect();

    $texte = finishedAlertText($this->adminUser->id);

    expect($texte)->toContain('AJUSTEMENT PRODUIT FINI')
        ->and($texte)->toContain('Poulet entier congelé')
        ->and($texte)->toContain('critique');
});

test('une HAUSSE est signalée sans crier au vol', function () {
    // Presque toujours une entrée oubliée. L'annoncer en critique userait
    // l'attention qu'on veut garder pour les diminutions.
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.finished.adjust', $this->produit), [
            'new_quantity_kg' => 150,
            'reason'          => 'Découpe non saisie',
        ]);

    $texte = finishedAlertText($this->adminUser->id);

    expect($texte)->toContain('AJUSTEMENT PRODUIT FINI')
        ->and($texte)->not->toContain('critique');
});

test('une DESTRUCTION est TOUJOURS critique', function () {
    // Elle met la ligne entière à zéro : c'est le mouvement le plus lourd du
    // magasin, quelle que soit la quantité concernée.
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.finished.dispose', $this->produit), [
            'reason' => 'Rupture de chaîne du froid — saisie sanitaire',
        ])->assertRedirect();

    $texte = finishedAlertText($this->adminUser->id);

    expect($texte)->toContain('DESTRUCTION PRODUIT FINI')
        ->and($texte)->toContain('critique')
        ->and($texte)->toContain('justificatif sanitaire');
});

test('l’alerte porte le MOTIF saisi', function () {
    // Sans le motif, l'information n'est pas actionnable : « 40 kg en moins » ne
    // dit pas s'il faut s'inquiéter.
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.finished.adjust', $this->produit), [
            'new_quantity_kg' => 80,
            'reason'          => 'Casse au transport vers Kindia',
        ]);

    expect(finishedAlertText($this->adminUser->id))->toContain('Casse au transport vers Kindia');
});

test('l’alerte mène à l’écran des produits finis', function () {
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.finished.adjust', $this->produit), [
            'new_quantity_kg' => 80, 'reason' => 'Inventaire',
        ]);

    expect(finishedAlertText($this->adminUser->id))
        ->toContain(route('slaughter.finished', absolute: false));
});

test('la TRAÇABILITÉ en base est conservée', function () {
    // L'alerte s'ajoute au journal, elle ne le remplace pas : une notification se
    // dissipe, une ligne de journal se requête.
    $this->actingAs($this->adminUser)
        ->post(route('slaughter.finished.adjust', $this->produit), [
            'new_quantity_kg' => 80, 'reason' => 'Inventaire physique',
        ]);

    $trace = FinishedProductAdjustment::latest('id')->first();

    expect((float) $trace->old_kg)->toBe(120.0)
        ->and((float) $trace->new_kg)->toBe(80.0)
        ->and($trace->reason)->toBe('Inventaire physique');
});

test('une alerte en échec n’empêche PAS la correction', function () {
    // On n'entrave pas le geste : détruire un lot périmé est une obligation
    // sanitaire, et corriger un inventaire une nécessité comptable.
    app()->bind(\App\Services\NotificationHub::class, fn () => throw new \RuntimeException('canal mort'));

    $this->actingAs($this->adminUser)
        ->post(route('slaughter.finished.dispose', $this->produit), [
            'reason' => 'Péremption',
        ])->assertRedirect();

    expect((float) $this->produit->fresh()->current_quantity_kg)->toBe(0.0)
        ->and(FinishedProductAdjustment::count())->toBe(1);
});

test('les DEUX chemins de réécriture alertent désormais', function () {
    // Garde-fou de forme : un troisième écran qui réécrirait la quantité d'un
    // produit fini sans alerter rouvrirait le même trou.
    $source = file_get_contents(app_path('Http/Controllers/SlaughterController.php'));

    expect(substr_count($source, 'alertFinishedProductAdjustment'))->toBe(2);
});
