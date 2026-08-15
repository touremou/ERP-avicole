<?php

uses(Tests\TestCase::class);

/*
 * LES RÉFÉRENTIELS RECOPIÉS DANS L'APPLICATION TERRAIN AVAIENT DÉRIVÉ.
 *
 * Quinze écrans de la PWA portent une liste de choix accompagnée du commentaire
 * « Miroir de App\Models\X::CONST ». Un miroir, donc — mais rien ne le
 * vérifiait, et l'un d'eux avait cessé de refléter sa source : l'écran des
 * dépenses ignorait la catégorie `achat_animaux` (« Achat d'animaux vifs »),
 * présente au serveur, sur le formulaire du bureau, dans les rapports et dans
 * les budgets.
 *
 * CE QUE ÇA COÛTE. L'achat d'animaux vifs est l'un des tout premiers postes de
 * dépense d'un élevage. Depuis le terrain, il ne pouvait qu'être classé
 * ailleurs — « divers », le plus souvent. Le budget alloué à `achat_animaux`
 * restait donc éternellement à zéro consommé pendant que le fourre-tout
 * explosait, et l'analyse du coût de revient par poste s'en trouvait faussée.
 *
 * POURQUOI RIEN NE L'A VU. Le point d'entrée de la synchro validait la
 * catégorie en TEXTE LIBRE (`string|max:50`) là où le formulaire du bureau
 * impose la liste. Le serveur acceptait donc n'importe quoi sans broncher —
 * y compris le silence d'un référentiel qui s'écarte. Les deux référentiels
 * (catégorie ET mode de paiement) sont désormais fermés des deux côtés.
 *
 * CE GARDE-FOU EST DÉRIVÉ, PAS ÉCRIT À LA MAIN : il lit les miroirs déclarés
 * dans les sources de la PWA et les compare aux constantes PHP. Une liste
 * d'écrans tenue à la main aurait exactement le défaut qu'elle prétend
 * surveiller — c'est la leçon des lots précédents.
 *
 * TROIS MIROIRS SONT VOLONTAIREMENT PARTIELS : un écran de CCP qui ne relève
 * que les points FROIDS, et deux écrans de « ronde » qui parcourent les flux
 * standards sans le « autre » que porte leur écran unitaire. Ils le DISENT,
 * dans leur propre commentaire — « Miroir PARTIEL de … » — et c'est cette
 * mention que le test lit. L'exception vit à côté du code qu'elle décrit, pas
 * dans une liste au fond d'un test.
 *
 * Le troisième (sous-produits d'abattoir) a été trouvé par ce garde-fou même,
 * pendant son écriture : son intention était juste, elle n'était simplement pas
 * dite. C'est exactement ce qu'on attend de lui.
 */

/**
 * Miroirs déclarés dans les sources de la PWA.
 *
 * @return array<int, array{file: string, class: string, const: string, partial: bool, keys: array<int,string>}>
 */
function declaredMirrors(): array
{
    $mirrors = [];

    foreach (glob(base_path('mobile/src/features/*/*.tsx')) as $file) {
        $source = file_get_contents($file);

        // Commentaire de miroir, puis le littéral qui le suit immédiatement.
        $motif = '#/\*\*[^*]*?Miroir (?<partiel>PARTIEL )?de (?<classe>App\\\\Models\\\\[A-Za-z]+)::(?<constante>[A-Z_]+).*?\*/\s*const\s+\w+[^=]*=\s*(?<ouvrant>[\{\[])(?<corps>.*?)\n(\}|\])#s';

        if (! preg_match_all($motif, $source, $sets, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($sets as $set) {
            $mirrors[] = [
                'file'    => str_replace(base_path() . '/', '', $file),
                'class'   => $set['classe'],
                'const'   => $set['constante'],
                'partial' => $set['partiel'] !== '',
                'keys'    => mirrorKeys($set['corps']),
            ];
        }
    }

    return $mirrors;
}

/**
 * Clés portées par un littéral de miroir. Deux formes coexistent dans la PWA :
 * l'objet « clé: 'Libellé' » et le tableau d'options « { value: 'clé', … } ».
 *
 * @return array<int,string>
 */
function mirrorKeys(string $body): array
{
    if (preg_match_all("#\bvalue:\s*'([^']+)'#", $body, $m)) {
        return $m[1];
    }

    preg_match_all("#^\s*'?([A-Za-z0-9_]+)'?\s*:#m", $body, $m);

    return $m[1];
}

test('des miroirs sont bien déclarés, et le test les trouve', function () {
    // Un test dérivé qui ne trouve rien passerait à vide : c'est le piège des
    // regex, déjà rencontré sur le dépouillement des commentaires (#235).
    $mirrors = declaredMirrors();

    expect(count($mirrors))->toBeGreaterThanOrEqual(10);

    foreach ($mirrors as $mirror) {
        expect($mirror['keys'])->not->toBeEmpty("Miroir sans clés lues : {$mirror['file']} ({$mirror['const']})");
    }
});

test('aucun miroir n’invente une clé que le serveur ignore', function () {
    // La direction dangereuse : le terrain envoie une valeur que le serveur ne
    // connaît pas. Aucune exception admise, même pour un miroir partiel.
    $ecarts = [];

    foreach (declaredMirrors() as $mirror) {
        $reference = referenceKeys($mirror);

        foreach (array_diff($mirror['keys'], $reference) as $inconnue) {
            $ecarts[] = "{$mirror['file']} : « {$inconnue} » absent de {$mirror['class']}::{$mirror['const']}";
        }
    }

    expect($ecarts)->toBe([]);
});

test('aucun miroir COMPLET n’omet une clé du serveur', function () {
    /*
     * L'autre direction : le terrain ne peut pas choisir une valeur pourtant
     * offerte au bureau. C'est le défaut trouvé — `achat_animaux` manquait aux
     * dépenses, et l'achat d'animaux vifs partait donc en « divers ».
     *
     * Les miroirs qui se déclarent PARTIELS dans leur propre commentaire sont
     * hors de cette règle : leur restriction est un choix d'écran, écrit sur
     * place.
     */
    $ecarts = [];

    foreach (declaredMirrors() as $mirror) {
        if ($mirror['partial']) {
            continue;
        }

        foreach (array_diff(referenceKeys($mirror), $mirror['keys']) as $manquante) {
            $ecarts[] = "{$mirror['file']} : « {$manquante} » de {$mirror['class']}::{$mirror['const']} absente du terrain";
        }
    }

    expect($ecarts)->toBe([]);
});

test('un miroir PARTIEL reste un sous-ensemble, pas autre chose', function () {
    // La mention « PARTIEL » autorise à omettre, jamais à diverger.
    foreach (declaredMirrors() as $mirror) {
        if (! $mirror['partial']) {
            continue;
        }

        expect(array_diff($mirror['keys'], referenceKeys($mirror)))->toBe([]);
    }
});

test('la catégorie de dépense du terrain est fermée, comme au bureau', function () {
    // La cause profonde : le serveur acceptait n'importe quelle chaîne sur ce
    // chemin, donc rien ne pouvait signaler la dérive.
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Services/Sync/SyncService.php')));

    expect($code)->toContain("Rule::in(array_keys(\\App\\Models\\Expense::CATEGORIES))")
        ->and($code)->toContain("Rule::in(array_keys(\\App\\Models\\Expense::PAYMENT_METHODS))")
        ->and($code)->not->toContain("'category'       => 'required|string|max:50'");
});

/**
 * Clés de la constante PHP visée par un miroir.
 *
 * @param array{class: string, const: string} $mirror
 * @return array<int,string>
 */
function referenceKeys(array $mirror): array
{
    $nom = "{$mirror['class']}::{$mirror['const']}";

    expect(defined($nom))->toBeTrue("Constante inexistante : {$nom} (miroir de {$mirror['file']})");

    $valeur = constant($nom);

    return array_is_list($valeur) ? $valeur : array_keys($valeur);
}
