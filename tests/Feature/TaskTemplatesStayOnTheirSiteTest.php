<?php

use App\Models\Farm;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Services\TaskSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE MODÈLE D'UN SITE FABRIQUAIT DES TÂCHES SUR TOUS LES AUTRES.
 *
 * `task_templates.farm_id` est NULLABLE, et les deux valeurs ont un sens :
 *
 *   • NULL — modèle GLOBAL, posé par les migrations (« Alimentation matin »,
 *     « Relevé eau »…), qui vaut pour toute l'exploitation ;
 *   • un identifiant — modèle créé depuis un écran, à qui `BelongsToFarm`
 *     attribue le site courant.
 *
 * Les deux lecteurs — le générateur nocturne et l'écran des modèles — lisaient
 * `withoutGlobalScopes()` tout court, donc les modèles de TOUS les sites. Et
 * tous deux l'affirmaient en commentaire : « Templates = globaux (pas de
 * farm_id) », que le modèle contredit depuis qu'il porte le trait.
 *
 * Mesuré : un modèle quotidien créé sur le site A produit sa tâche sur le
 * site B — tous les matins, indéfiniment, sur un site qui n'a jamais rien
 * demandé. Et l'écran des modèles du site B listait celui du site A, donc
 * permettait de le désactiver ou de le supprimer pour l'autre.
 *
 * ─── LA RÈGLE ───
 *
 * Un site applique les modèles GLOBAUX plus les SIENS. La règle vit désormais
 * sur le modèle (`TaskTemplate::forFarm`), et les deux lecteurs l'y prennent.
 *
 * Sans ferme — installation mono-site, ou aucune ferme active — rien n'est
 * filtré : c'est le comportement historique, et le seul qui ait un sens.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->siteB = Farm::create([
        'code' => 'SITE-B', 'name' => 'Site Kindia', 'is_active' => true,
    ]);

    foreach ([$this->farm->id, $this->siteB->id] as $siteId) {
        DB::table('farm_user')->updateOrInsert(
            ['farm_id' => $siteId, 'user_id' => $this->adminUser->id],
            [
                'is_default' => $siteId === $this->farm->id,
                'is_owner'   => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }
});

/**
 * Un modèle quotidien de niveau FERME (indépendant des bâtiments).
 *
 * `$farmId = null` fabrique un modèle GLOBAL, comme ceux des migrations. Il faut
 * pour cela remettre la colonne à null APRÈS coup : `BelongsToFarm` attribue le
 * site courant à la création, et c'est exactement ce qui rend un modèle saisi à
 * l'écran propriété de son site.
 */
function modeleQuotidien(?int $farmId, string $nom): TaskTemplate
{
    $modele = TaskTemplate::create([
        'farm_id'          => $farmId,
        'name'             => $nom,
        'category'         => 'controle',
        'frequency'        => 'quotidien',
        'scheduled_time'   => '08:00',
        'duration_minutes' => 30,
        'target_type'      => 'farm',
        'per_building'     => false,
        'priority'         => 'normale',
        'is_active'        => true,
    ]);

    if ($farmId === null) {
        DB::table('task_templates')->where('id', $modele->id)->update(['farm_id' => null]);
        $modele->refresh();
    }

    return $modele;
}

/** Tâches générées pour ce site à partir de ce modèle. */
function tachesIssuesDu(TaskTemplate $modele, int $farmId): int
{
    return TaskAssignment::withoutGlobalScopes()
        ->where('task_template_id', $modele->id)
        ->where('farm_id', $farmId)
        ->count();
}

test('le modèle d’un site ne génère RIEN sur un autre site', function () {
    /*
     * LE défaut : « Rituel du site A » tombait chaque matin sur le site B.
     */
    $modeleDuSiteA = modeleQuotidien($this->farm->id, 'Rituel du site A');

    app(TaskSchedulerService::class)->generateForDate(today(), $this->siteB->id);

    expect(tachesIssuesDu($modeleDuSiteA, $this->siteB->id))->toBe(0);
});

test('le modèle génère bien sur SON site — non-régression', function () {
    /*
     * LA borne : on cloisonne, on ne débranche pas. Un modèle doit continuer de
     * produire là où il a été créé.
     */
    $modeleDuSiteA = modeleQuotidien($this->farm->id, 'Rituel du site A');

    app(TaskSchedulerService::class)->generateForDate(today(), $this->farm->id);

    expect(tachesIssuesDu($modeleDuSiteA, $this->farm->id))->toBe(1);
});

test('un modèle GLOBAL génère sur les DEUX sites — non-régression', function () {
    /*
     * L'autre moitié de la règle, et la plus importante : les modèles posés par
     * les migrations n'ont pas de site, et doivent valoir partout. Les
     * cloisonner aurait vidé le planning de tous les sites d'un coup.
     */
    $global = modeleQuotidien(null, 'Ronde du soir');

    app(TaskSchedulerService::class)->generateForDate(today(), $this->farm->id);
    app(TaskSchedulerService::class)->generateForDate(today(), $this->siteB->id);

    expect(tachesIssuesDu($global, $this->farm->id))->toBe(1)
        ->and(tachesIssuesDu($global, $this->siteB->id))->toBe(1);
});

test('l’écran des modèles ne montre pas ceux des autres sites', function () {
    /*
     * La seconde moitié du défaut : listé ailleurs, donc modifiable ailleurs.
     * Le responsable du site B pouvait désactiver ou supprimer le modèle du
     * site A — un planning qui s'arrête sans que personne sur place n'ait rien
     * touché.
     */
    modeleQuotidien($this->farm->id, 'Rituel du site A');
    modeleQuotidien($this->siteB->id, 'Rituel du site B');

    session(['current_farm_id' => $this->siteB->id]);

    $page = $this->get(route('tasks.templates'));

    $page->assertOk()
        ->assertSee('Rituel du site B')
        ->assertDontSee('Rituel du site A');
});

test('l’écran montre toujours les modèles GLOBAUX — non-régression', function () {
    $global = modeleQuotidien(null, 'Ronde du soir');

    session(['current_farm_id' => $this->siteB->id]);

    $this->get(route('tasks.templates'))->assertOk()->assertSee($global->name);
});

test('sans ferme désignée, rien n’est filtré — non-régression', function () {
    /*
     * L'installation mono-site, et le repli du cron quand aucune ferme active
     * n'existe : `generateForDate($date, null)`. Filtrer là serait tout couper.
     */
    $modele = modeleQuotidien($this->farm->id, 'Rituel du site A');

    expect(TaskTemplate::forFarm(null)->pluck('id'))->toContain($modele->id);
});
