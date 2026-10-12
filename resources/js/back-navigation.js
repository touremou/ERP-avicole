/**
 * LA FLÈCHE « RETOUR » RAMÈNE LÀ D'OÙ L'ON VIENT.
 *
 * Signalé par l'exploitation : « certains retours ne ramènent pas sur la page
 * précédente, mais dans un autre module ». Chaque flèche suivait un lien FIXE,
 * déduit du nom de la route : `daily-checks.create` → `daily-checks.index`.
 * Ouvert depuis la fiche d'un lot, le pointage renvoyait donc au registre
 * général des pointages, pas au lot ; un achat ouvert depuis un fournisseur,
 * à la liste des achats.
 *
 * Règle : si la page précédente est une page de l'application, et qu'il y a
 * un historique où revenir, la flèche y revient — avec ses filtres et sa
 * position. Sinon (onglet ouvert directement, lien externe), le lien fixe
 * reste le repli.
 *
 * On ne revient PAS sur :
 *   • un formulaire (`/create`, `/edit`) — après un enregistrement, la page
 *     précédente est le formulaire qu'on vient de valider ;
 *   • la même page (rechargée après une action sur elle-même) ;
 *   • la connexion.
 *
 * Fonction PURE, éprouvée sans navigateur (`node --test`).
 */
export function retourParHistorique(referrer, location, longueurHistorique) {
    if (!referrer || longueurHistorique < 2) return false;

    let precedente;
    try {
        precedente = new URL(referrer);
    } catch {
        return false;
    }

    if (precedente.origin !== location.origin) return false;
    if (precedente.pathname === location.pathname) return false;
    if (/\/(create|edit)\/?$/.test(precedente.pathname)) return false;
    if (/^\/(login|logout|register|forgot-password|reset-password)(\/|$)/.test(precedente.pathname)) return false;

    return true;
}

/** Branche la règle sur toutes les flèches `[data-back]` de la page. */
export function brancherRetour(doc = globalThis.document, win = globalThis.window) {
    doc?.addEventListener('click', (e) => {
        const lien = e.target?.closest?.('a[data-back]');
        if (!lien || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;

        if (retourParHistorique(doc.referrer, win.location, win.history.length)) {
            e.preventDefault();
            win.history.back();
        }
    });
}
