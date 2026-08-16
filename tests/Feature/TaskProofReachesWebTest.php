<?php

use App\Models\TaskAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE RÈGLE QUI SE DIT « AUTORITAIRE SERVEUR » NE TENAIT QU'UNE PORTE SUR DEUX.
 *
 * La synchro mobile vérifie la preuve d'exécution, et le dit en ces termes :
 *
 *   « Preuve d'exécution — VÉRIFICATION AUTORITAIRE serveur : une preuve
 *     manquante est un refus NON REJOUABLE […] Empêche de clôturer sans la
 *     photo/valeur exigée, MÊME SI UN CLIENT ALTÉRÉ TENTAIT DE PASSER OUTRE. »
 *
 * Le formulaire web du MÊME serveur clôturait n'importe quelle tâche d'un clic.
 * Mesuré : une tâche « Désinfection du bâtiment B2 », preuve photo exigée,
 * passe à « fait » depuis le bureau avec `proof_photo_path` à null et un
 * message de succès. Pas besoin d'un client altéré : le navigateur suffisait.
 *
 * Deux règles manquaient au web, toutes deux tenues par la synchro :
 *
 *   1. LA PREUVE — photo ou valeur chiffrée selon le modèle de tâche ;
 *   2. LE VERROU DE PRISE — une tâche prise par un autre technicien ne se
 *      clôture pas à sa place. La synchro explique le motif : sans lui, le
 *      verrou « serait contournable en sautant l'étape prendre ». Il l'était,
 *      en passant du téléphone au bureau.
 *
 * ─── POURQUOI CELA COMPTE ICI ───
 *
 * Le promoteur est à l'étranger. La photo de désinfection n'est pas une
 * formalité : c'est la seule chose qui distingue un bâtiment traité d'une case
 * cochée. Une exigence contournable par le chemin le plus commode ne mesure
 * plus rien.
 *
 * ─── LA VALEUR CHIFFRÉE, ELLE, SE SAISIT AU BUREAU ───
 *
 * On ne remplace pas un trou par un mur. Un relevé chiffré peut légitimement
 * être reporté depuis le bureau : le champ est proposé dans la liste et le
 * contrôleur l'enregistre. Seule la PHOTO reste réservée au terrain — rien au
 * bureau ne peut tenir lieu d'un cliché pris sur place —, et le refus dit par
 * où passer.
 *
 * ─── UNE DÉCLARATION, DEUX APPELANTS ───
 *
 * `TaskAssignment::requiresProof()` existait déjà : déclarée, couverte par un
 * test, appelée par AUCUN code de production — pendant que la synchro recopiait
 * la condition à la main. La règle vit maintenant dans `missingProof()`, et les
 * deux chemins l'appellent.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Une tâche du jour, avec l'exigence de preuve demandée. */
function tacheAvecPreuve(int $farmId, string $type, array $extra = []): TaskAssignment
{
    return TaskAssignment::create(array_merge([
        'farm_id' => $farmId,
        'title' => 'Désinfection du bâtiment B2',
        'category' => 'sanitaire',
        'scheduled_date' => now()->toDateString(),
        'status' => 'a_faire',
        'proof_type' => $type,
        'proof_label' => $type === 'valeur' ? 'Poids moyen' : 'Photo du bâtiment',
        'proof_unit' => $type === 'valeur' ? 'g' : null,
    ], $extra));
}

test('clôturer au bureau une tâche à PREUVE PHOTO est refusé', function () {
    // LE défaut : « fait », photo nulle, message de succès.
    $tache = tacheAvecPreuve($this->farm->id, 'photo');

    $this->post(route('tasks.complete', $tache))->assertRedirect();

    expect($tache->fresh()->status)->toBe('a_faire')
        ->and($tache->fresh()->proof_photo_path)->toBeNull();
});

test('le refus dit par où passer', function () {
    // Un refus sans issue pousse à contourner — ou à ne plus rien cocher.
    $tache = tacheAvecPreuve($this->farm->id, 'photo');

    $this->post(route('tasks.complete', $tache));

    expect(session('error'))->toContain('mobile');
});

test('clôturer une tâche à VALEUR sans la valeur est refusé', function () {
    $tache = tacheAvecPreuve($this->farm->id, 'valeur');

    $this->post(route('tasks.complete', $tache))->assertRedirect();

    expect($tache->fresh()->status)->toBe('a_faire');
});

test('la valeur chiffrée saisie au bureau clôture la tâche ET s’enregistre', function () {
    /*
     * On ne remplace pas un trou par un mur : un relevé peut être reporté du
     * bureau. Ce qui manquait, c'est qu'il soit EXIGÉ — et conservé.
     */
    $tache = tacheAvecPreuve($this->farm->id, 'valeur');

    $this->post(route('tasks.complete', $tache), ['proof_value' => 1850.5]);

    expect($tache->fresh()->status)->toBe('fait')
        ->and((float) $tache->fresh()->proof_value)->toBe(1850.5);
});

test('une tâche SANS exigence de preuve se coche toujours d’un clic', function () {
    // La borne : l'immense majorité des tâches n'exige rien, et le geste
    // quotidien ne doit pas s'alourdir.
    $tache = tacheAvecPreuve($this->farm->id, 'aucune');

    $this->post(route('tasks.complete', $tache));

    expect($tache->fresh()->status)->toBe('fait');
});

test('une tâche PRISE par un autre ne se clôture pas à sa place', function () {
    /*
     * Le verrou que la synchro tient et que le bureau ignorait. Son propre
     * commentaire donne le motif : sinon il « serait contournable en sautant
     * l'étape prendre ».
     */
    $tache = tacheAvecPreuve($this->farm->id, 'aucune', [
        'status' => 'en_cours',
        'claimed_by' => $this->managerUser->id,
        'started_at' => now(),
    ]);

    $this->post(route('tasks.complete', $tache))->assertRedirect();

    expect($tache->fresh()->status)->toBe('en_cours');
});

test('celui QUI A PRIS la tâche la clôture normalement', function () {
    // Le verrou vise l'usurpation, pas le titulaire.
    $tache = tacheAvecPreuve($this->farm->id, 'aucune', [
        'status' => 'en_cours',
        'claimed_by' => $this->adminUser->id,
        'started_at' => now(),
    ]);

    $this->post(route('tasks.complete', $tache));

    expect($tache->fresh()->status)->toBe('fait');
});

test('une prise EXPIRÉE ne bloque plus personne', function () {
    /*
     * Le modèle libère une prise trop ancienne (CLAIM_TIMEOUT_MINUTES) : sans
     * cette borne, un technicien ayant oublié de clôturer bloquerait la tâche
     * pour tout le monde, indéfiniment.
     */
    $tache = tacheAvecPreuve($this->farm->id, 'aucune', [
        'status' => 'en_cours',
        'claimed_by' => $this->managerUser->id,
        'started_at' => now()->subMinutes(TaskAssignment::CLAIM_TIMEOUT_MINUTES + 10),
    ]);

    $this->post(route('tasks.complete', $tache));

    expect($tache->fresh()->status)->toBe('fait');
});

test('la liste n’offre pas le bouton d’un clic sur une tâche à preuve photo', function () {
    /*
     * L'écran et la garde doivent dire la même chose — la leçon des divergences
     * précédentes. Un bouton qui refuse systématiquement est un piège.
     */
    tacheAvecPreuve($this->farm->id, 'photo');

    $reponse = $this->get(route('tasks.index'))->assertOk();

    $reponse->assertSee('application mobile', false)
        ->assertSee('fa-camera', false);
});

test('la liste propose le champ de saisie sur une tâche à preuve chiffrée', function () {
    // Le pendant : là où la garde exige, l'écran doit fournir de quoi répondre.
    tacheAvecPreuve($this->farm->id, 'valeur');

    $this->get(route('tasks.index'))->assertOk()->assertSee('name="proof_value"', false);
});
