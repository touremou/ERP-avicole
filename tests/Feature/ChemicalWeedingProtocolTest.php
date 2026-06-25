<?php

use App\Models\CropProtocol;
use Database\Seeders\CropProtocolSeeder;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CropProtocolSeeder::class);
});

test('le seeder crée un itinéraire maïs « désherbage chimique » sans sarclage manuel', function () {
    $mais = CropProtocol::where('name', 'Itinéraire Maïs — désherbage chimique')->first();

    expect($mais)->not->toBeNull()
        ->and($mais->items()->count())->toBe(10)
        ->and($mais->items()->where('type', 'sarclage')->count())->toBe(0)            // plus de sarclage manuel
        ->and($mais->items()->where('type', 'traitement')->count())->toBeGreaterThan(2); // herbicides + phyto

    // Glyphosate total avant semis + désherbages sélectifs.
    expect($mais->items()->where('product_suggested', 'like', '%Glyphosate%')->exists())->toBeTrue()
        ->and($mais->items()->where('product_suggested', 'like', '%icosulfuron%')->exists())->toBeTrue();

    // Dosage pour pulvérisateur à dos 16 L présent dans les étapes.
    expect($mais->items()->where('dose', 'like', '%16 L%')->exists())->toBeTrue();
});

test('la description porte produits (Kindia), dosage 16 L et points de vigilance', function () {
    $mais = CropProtocol::where('name', 'Itinéraire Maïs — désherbage chimique')->first();

    expect($mais->description)
        ->toContain('Kindia')
        ->toContain('16 L')
        ->toContain('POINTS DE VIGILANCE')
        ->toContain('EPI')
        ->toContain('zone tampon');
});

test('les 8 variantes désherbage chimique existent et suppriment le sarclage manuel', function () {
    $crops = [
        'Maïs', 'Riz pluvial', 'Manioc', 'Arachide',
        'Tomate (repiquée)', 'Pomme de terre', 'Oignon (repiqué)', 'Haricot vert',
    ];
    foreach ($crops as $crop) {
        $p = CropProtocol::where('name', "Itinéraire {$crop} — désherbage chimique")->first();
        expect($p)->not->toBeNull("manque l'itinéraire {$crop}")
            ->and($p->items()->where('type', 'sarclage')->count())->toBe(0)
            ->and($p->items()->where('product_suggested', 'like', '%Glyphosate%')->exists())->toBeTrue()
            ->and($p->items()->where('dose', 'like', '%16 L%')->exists())->toBeTrue();
    }
});

test('le haricot vert (légumineuse) bannit 2,4-D / atrazine / metribuzine et utilise la bentazone', function () {
    $h = CropProtocol::where('name', 'Itinéraire Haricot vert — désherbage chimique')->first();

    expect($h->description)->toContain('LÉGUMINEUSE')->toContain('bentazone');

    $post = $h->items()->where('action_name', 'like', '%post-levée%')->first();
    expect($post->product_suggested)->toContain('Basagran')        // bentazone
        ->and($post->notes)->toContain('INTERDIT');

    foreach (['%2,4-D%', '%trazine%', '%etribuzine%'] as $banned) {
        expect($h->items()->where('product_suggested', 'like', $banned)->exists())->toBeFalse();
    }
});

test('l\'arachide (légumineuse) bannit 2,4-D / atrazine et utilise un graminicide sélectif', function () {
    $a = CropProtocol::where('name', 'Itinéraire Arachide — désherbage chimique')->first();

    expect($a->description)->toContain('LÉGUMINEUSE')->toContain('JAMAIS de 2,4-D');

    // L'étape post-levée propose un graminicide et interdit explicitement le 2,4-D.
    $post = $a->items()->where('action_name', 'like', '%post-levée%')->first();
    expect($post)->not->toBeNull()
        ->and($post->product_suggested)->toContain('Gallant')
        ->and($post->notes)->toContain('INTERDIT');

    // Aucune étape ne propose 2,4-D ni atrazine comme produit (détruiraient la légumineuse).
    expect($a->items()->where('product_suggested', 'like', '%2,4-D%')->exists())->toBeFalse()
        ->and($a->items()->where('product_suggested', 'like', '%trazine%')->exists())->toBeFalse();
});

test('l\'itinéraire riz interdit le 2,4-D à l\'épiaison (garde-fou phytotoxicité)', function () {
    $riz = CropProtocol::where('name', 'Itinéraire Riz pluvial — désherbage chimique')->first();

    expect($riz)->not->toBeNull()
        ->and($riz->items()->where('type', 'sarclage')->count())->toBe(0)
        ->and($riz->description)->toContain('2,4-D');

    // L'étape 2,4-D précise le garde-fou de stade.
    $herbicide24d = $riz->items()->where('action_name', 'like', '%2,4-D%')->first();
    expect($herbicide24d)->not->toBeNull()
        ->and($herbicide24d->notes)->toContain('JAMAIS')
        ->and($herbicide24d->notes)->toContain('épiaison');
});
