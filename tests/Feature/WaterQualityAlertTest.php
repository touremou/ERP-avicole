<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\DailyCheckExtension;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'EAU CRITIQUE N'ALERTAIT PERSONNE.
 *
 * `DailyCheckExtension::getWaterAlerts()` calculait déjà tout : trois niveaux, seuils
 * réglables depuis Paramètres › Pisciculture, messages clairs. Mais elle n'était
 * consommée que par TROIS ÉCRANS — le tableau de bord, un rapport, et la fiche du
 * lot. Aucune notification n'en partait.
 *
 * Une chute d'oxygène tue un bassin en quelques heures : c'est le risque le plus
 * RAPIDE de l'exploitation. L'alerte n'atteignait pourtant que la personne qui
 * ouvrait la page — ni le promoteur, qui vit à l'étranger, ni même le technicien qui
 * venait de saisir le relevé.
 *
 * Toutes les autres familles d'alerte de cette application passent par la chaîne :
 * mortalité, péremption, registres HACCP, contrats, écart de caisse. La qualité de
 * l'eau, la plus urgente, ne le faisait pas.
 *
 * ─── CE QUI NE DÉCLENCHE PAS, ET POURQUOI ───
 *
 *   • les niveaux « warning » : un avertissement à chaque dérive de pH de 0,2
 *     apprendrait à ignorer le canal, et c'est l'asphyxie qu'on veut voir passer.
 *     Même raisonnement que l'excédent de caisse (#229) ou la sauvegarde saine
 *     (#226) — ce qui crie tout le temps ne se lit plus ;
 *   • une modification qui ne touche AUCUNE mesure d'eau : corriger la production
 *     laitière du même pointage ne doit pas ré-alerter sur une eau inchangée.
 *
 * ─── PORTÉE ───
 *
 * La PWA n'offre aucun champ de qualité d'eau : la pisciculture est aujourd'hui un
 * module WEB uniquement. Ce n'est pas un défaut mais un périmètre produit, et ce lot
 * ne l'élargit pas.
 */

beforeEach(function () {
    $this->setUpRbac();

    // L'état réel de l'exploitation : le WhatsApp ne sort pas. L'alerte doit donc
    // exister sur les autres canaux (cf. #216).
    Setting::set('whatsapp.driver', 'log');

    $this->batch = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'initial_quantity' => 5000, 'current_quantity' => 5000,
    ]);

    $this->check = DailyCheck::create([
        'farm_id'    => $this->farm->id,
        'batch_id'   => $this->batch->id,
        'user_id'    => $this->adminUser->id,
        'check_date' => now()->toDateString(),
    ]);
});

/**
 * Texte lisible de la dernière notification.
 *
 * Le JSON échappe DEUX choses qui font échouer une assertion naïve : les accents
 * (« ÉCART » devient \u00c9CART) et les barres obliques (« /batches/1 » devient
 * « \/batches\/1 »). Les deux m'ont piégé — la première au lot #229, la seconde ici.
 * On décode donc explicitement les unes et les autres.
 */
function waterAlertText(int $userId): string
{
    $raw = DB::table('notifications')->where('notifiable_id', $userId)->latest('created_at')->value('data');

    return json_encode(json_decode((string) $raw, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
}

test('une chute d’OXYGÈNE alerte immédiatement', function () {
    // Le cas qui justifie tout ce lot : l'asphyxie tue en heures, pas en jours.
    DailyCheckExtension::create([
        'daily_check_id' => $this->check->id,
        'water_o2_ppm'   => 2.0,          // seuil critique : 4
    ]);

    expect(waterAlertText($this->adminUser->id))->toContain('EAU CRITIQUE')
        ->and(waterAlertText($this->adminUser->id))->toContain('asphyxie');
});

test('l’AMMONIAQUE au-dessus du seuil alerte', function () {
    DailyCheckExtension::create([
        'daily_check_id'     => $this->check->id,
        'water_ammonia_ppm'  => 2.5,      // seuil critique : 1
    ]);

    expect(waterAlertText($this->adminUser->id))->toContain('intoxication');
});

test('une eau SAINE n’alerte pas', function () {
    // Le pendant indispensable : une alerte à chaque relevé finirait ignorée.
    DailyCheckExtension::create([
        'daily_check_id'    => $this->check->id,
        'water_o2_ppm'      => 7.0,
        'water_ph'          => 7.2,
        'water_ammonia_ppm' => 0.1,
        'water_temp'        => 28,
    ]);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe(0);
});

test('un simple AVERTISSEMENT n’alerte pas', function () {
    // pH 6,3 pour une plage optimale 6,5–8,5 : hors plage, mais pas critique
    // (le seuil critique est à 0,5 de marge). Une alerte ici userait le canal.
    DailyCheckExtension::create([
        'daily_check_id' => $this->check->id,
        'water_ph'       => 6.3,
    ]);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe(0);
});

test('les SEUILS de l’exploitation sont respectés, pas des valeurs codées', function () {
    // Les seuils sont réglables depuis Paramètres › Pisciculture : une eau saumâtre
    // ou une espèce résistante n'a pas les mêmes exigences. Un seuil réglé qui
    // n'aurait aucun effet serait le défaut symétrique (cf. #218).
    Setting::set('pisciculture.o2_alert', 6);
    Setting::clearCache();

    DailyCheckExtension::create([
        'daily_check_id' => $this->check->id,
        'water_o2_ppm'   => 5.0,   // sain au seuil livré (4), critique au seuil réglé (6)
    ]);

    expect(waterAlertText($this->adminUser->id))->toContain('EAU CRITIQUE');
});

test('l’alerte NOMME le lot et mène à sa fiche', function () {
    // Sans le lot, l'information n'est pas actionnable : il faut savoir QUEL bassin.
    DailyCheckExtension::create([
        'daily_check_id' => $this->check->id,
        'water_o2_ppm'   => 2.0,
    ]);

    $texte = waterAlertText($this->adminUser->id);

    expect($texte)->toContain($this->batch->code)
        ->and($texte)->toContain(route('batches.show', $this->batch->id, absolute: false));
});

test('corriger un champ SANS rapport ne ré-alerte pas', function () {
    // Le pointage porte aussi la production laitière : la corriger ne doit pas
    // relancer une alerte sur une eau inchangée.
    $ext = DailyCheckExtension::create([
        'daily_check_id' => $this->check->id,
        'water_o2_ppm'   => 2.0,
    ]);

    $avant = DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count();

    $ext->update(['milk_liters' => 12]);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe($avant);
});

test('corriger une MESURE D’EAU ré-évalue et alerte', function () {
    // Le pendant : une eau devenue critique après correction du relevé doit alerter.
    $ext = DailyCheckExtension::create([
        'daily_check_id' => $this->check->id,
        'water_o2_ppm'   => 7.0,       // saine
    ]);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe(0);

    $ext->update(['water_o2_ppm' => 2.0]);

    expect(waterAlertText($this->adminUser->id))->toContain('EAU CRITIQUE');
});

test('une alerte en échec n’empêche PAS l’enregistrement du relevé', function () {
    // Perdre la mesure serait pire que perdre l'alerte : c'est elle qui porte
    // l'information.
    app()->bind(\App\Services\NotificationHub::class, fn () => throw new \RuntimeException('canal mort'));

    $ext = DailyCheckExtension::create([
        'daily_check_id' => $this->check->id,
        'water_o2_ppm'   => 2.0,
    ]);

    expect((float) $ext->fresh()->water_o2_ppm)->toBe(2.0);
});
