/**
 * UNE SAISIE REFUSÉE ROUVRE SA FENÊTRE, REMPLIE.
 *
 * Plusieurs écrans saisissent dans une fenêtre modale cachée au chargement
 * (laboratoire et stock des matières premières, machines de la provenderie).
 * Un refus du serveur ramenait la page avec la fenêtre FERMÉE : seul un
 * bandeau de quelques secondes disait pourquoi, et tout était à ressaisir —
 * jusqu'à l'adresse du formulaire, que ces écrans posent en JavaScript à
 * l'ouverture.
 *
 * Une fenêtre marquée `data-modale` (son `id` fait foi) :
 *   • à l'envoi, son formulaire emporte `_modale` et `_action` ;
 *   • au retour d'un refus, le layout rend `window.AVISMART_MODALE`
 *     (`{ id, action, old }`) et la fenêtre se rouvre, adresse et champs
 *     restaurés.
 */

/** Ce que l'on peut remettre dans un champ : ni jeton, ni méthode, ni secret. */
export function champsARestaurer(ancien) {
    return Object.entries(ancien ?? {}).filter(([nom, valeur]) =>
        !nom.startsWith('_')
        && !/password/i.test(nom)
        && (typeof valeur === 'string' || typeof valeur === 'number'));
}

/** Une adresse de formulaire n'est restaurée que sur ce site. */
export function actionSure(action, origine) {
    if (!action) return null;
    try {
        const url = new URL(action, origine);
        return url.origin === origine ? url.href : null;
    } catch {
        return null;
    }
}

export function brancherModales(doc = globalThis.document, win = globalThis.window) {
    if (!doc) return;

    doc.addEventListener('submit', (e) => {
        const form = e.target;
        const modale = form?.closest?.('[data-modale]');
        if (!modale) return;

        for (const [nom, valeur] of [['_modale', modale.id], ['_action', form.action]]) {
            let champ = form.querySelector(`input[name="${nom}"]`);
            if (!champ) {
                champ = doc.createElement('input');
                champ.type = 'hidden';
                champ.name = nom;
                form.appendChild(champ);
            }
            champ.value = valeur;
        }
    }, true);

    const etat = win?.AVISMART_MODALE;
    if (!etat?.id) return;

    const modale = doc.getElementById(etat.id);
    const form = modale?.querySelector('form');
    if (!modale || !form) return;

    const action = actionSure(etat.action, win.location.origin);
    if (action) form.action = action;

    for (const [nom, valeur] of champsARestaurer(etat.old)) {
        const champ = form.elements.namedItem(nom);
        if (champ && 'value' in champ && champ.type !== 'file') champ.value = valeur;
    }

    modale.classList.remove('hidden');
}
