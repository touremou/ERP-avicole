/**
 * TRADUCTION, selon la convention de l'application : la clé EST le texte
 * français (`__()` côté Blade, `lang/en.json`). Le layout dépose les textes de
 * ce moteur, déjà traduits dans la langue de l'utilisateur, dans
 * `window.AVISMART_TEXTES` ; hors navigateur ou sans eux, le français fait foi.
 * Remplacements à la Laravel : `:n`, `:status`…
 */
export function traduire(fr, remplacements = {}) {
    const t = globalThis.AVISMART_TEXTES?.[fr] ?? fr;
    return Object.entries(remplacements).reduce((acc, [k, v]) => acc.replaceAll(`:${k}`, String(v)), t);
}

/**
 * QUE FAIRE D'UNE SAISIE HORS-LIGNE, SELON LA RÉPONSE DU SERVEUR ?
 *
 * Fonction PURE — ni DOM, ni Dexie — pour pouvoir être éprouvée sans
 * navigateur (`node --test 'resources/js/tests/*.test.js'`).
 *
 * Le moteur web décidait au cas par cas, et perdait des saisies de trois façons :
 *
 *   • un refus métier (`conflict` en 200 : stock insuffisant, jour déjà
 *     pointé, collecte déjà triée…) marquait la saisie « synchronisée » — elle
 *     DISPARAISSAIT, avec pour seule trace un `console.warn` que personne ne lit ;
 *   • les pointages étaient marqués synchronisés sur le seul `response.ok`,
 *     corps ignoré — un `conflict` y disparaissait aussi ;
 *   • un refus définitif (422 données invalides, 403 droit retiré) levait une
 *     exception, rattrapée en console, et la saisie était RENVOYÉE à chaque
 *     reconnexion, indéfiniment, sans que personne ne l'apprenne.
 *
 * L'application terrain traite ces cas depuis longtemps : un refus sort de la
 * file mais RESTE visible, avec son motif, dans un bac « À corriger ». Le web
 * suit désormais la même règle.
 *
 * @returns {{etat: 'synchronise'|'refuse'|'reessayer', motif: string|null}}
 */
export function issueDeSynchro(statutHttp, corps) {
    const body = corps ?? {};

    // Réseau tombé, serveur indisponible, ou réponse illisible : on réessaiera.
    if (!statutHttp || statutHttp >= 500 || statutHttp === 408 || statutHttp === 429) {
        return { etat: 'reessayer', motif: null };
    }

    // Session expirée (401) ou jeton CSRF périmé (419) : ce n'est pas la
    // SAISIE qui est refusée, c'est la session. Elle partira après reconnexion.
    if (statutHttp === 401 || statutHttp === 419) {
        return { etat: 'reessayer', motif: null };
    }

    if (statutHttp >= 200 && statutHttp < 300) {
        switch (body.status) {
            case 'success':
            case 'already_synced':
                return { etat: 'synchronise', motif: null };
            case 'conflict':
                return { etat: 'refuse', motif: body.message || traduire('Refusée par le serveur.') };
            case 'error':
                return { etat: 'reessayer', motif: null };
            default:
                // Ancien contrat sans statut dans le corps : le 2xx fait foi.
                return body.status === undefined
                    ? { etat: 'synchronise', motif: null }
                    : { etat: 'reessayer', motif: null };
        }
    }

    if (statutHttp === 403) {
        return { etat: 'refuse', motif: body.message || traduire('Droit insuffisant pour enregistrer cette saisie.') };
    }

    if (statutHttp === 422) {
        const premiere = Object.values(body.errors ?? {}).flat()[0];
        return { etat: 'refuse', motif: premiere || body.message || traduire('Données invalides.') };
    }

    // Tout autre 4xx est un refus définitif : le renvoyer ne le changera pas.
    return { etat: 'refuse', motif: body.message || traduire('Refusée par le serveur (HTTP :status).', { status: statutHttp }) };
}

/**
 * À QUI APPARTIENT UNE SAISIE EN FILE — et peut-on la pousser MAINTENANT ?
 *
 * Le serveur écrit toujours au nom de l'utilisateur AUTHENTIFIÉ, sur la ferme
 * ACTIVE de sa session. Or le navigateur d'un poste de bureau ou d'une
 * tablette de ferme passe de main en main, et l'on change de site en cours de
 * journée. Sans marque, la file partait telle quelle sous la session suivante :
 *   • la dépense saisie par le magasinier était enregistrée au nom du
 *     comptable qui se connectait après lui ;
 *   • une dépense sans lot, un lot créé hors-ligne, atterrissaient sur le site
 *     où l'on venait de basculer — pas sur celui où ils avaient été saisis.
 *
 * L'application terrain marque l'auteur depuis longtemps (`myPendingOperations`
 * dans mobile/src/offline/sync.ts). Le web suit la même règle, et y ajoute le
 * site : une saisie d'un autre compte ou d'un autre site RESTE en file, intacte,
 * et partira quand son auteur se reconnectera sur ce site. On ne détruit pas
 * du travail de terrain.
 *
 * Les saisies sans marque (antérieures à ce correctif) sont poussées : elles
 * précèdent la notion d'auteur, et les bloquer les condamnerait.
 */
export function contexteCourant(doc = globalThis.document) {
    const lire = (nom) => {
        const v = doc?.querySelector?.(`meta[name="${nom}"]`)?.getAttribute('content');
        return v ? Number(v) : null;
    };
    return { auteur_id: lire('avismart-user'), ferme_id: lire('avismart-farm') };
}

export function poussableIci(saisie, contexte) {
    // Personne de connecté (page de connexion) : on ne pousse rien.
    if (!contexte?.auteur_id) return false;
    if (saisie.auteur_id != null && saisie.auteur_id !== contexte.auteur_id) return false;
    if (saisie.ferme_id != null && contexte.ferme_id != null && saisie.ferme_id !== contexte.ferme_id) return false;
    return true;
}

/** Ce qui part au serveur : la saisie, sans les marques propres au navigateur. */
export function corpsAEnvoyer(saisie) {
    const { auteur_id, ferme_id, refus_motif, refus_le, ...corps } = saisie;
    return corps;
}

/** Valeurs de `is_synced` dans les files locales. */
export const EN_ATTENTE = 0;
export const SYNCHRONISEE = 1;
export const REFUSEE = 2;

/**
 * Range une saisie dans sa file locale selon la réponse du serveur, et rend
 * l'issue. `table` n'a besoin que d'une méthode `update(cle, champs)` — une
 * table Dexie en production, un double en test.
 *
 * C'est ICI que les anciennes versions perdaient les saisies : elles écrivaient
 * `is_synced: 1` sur un refus. Une saisie refusée reçoit désormais
 * `REFUSEE`, son motif et sa date — hors de la file de réessai, mais gardée.
 */
export async function rangerSaisie(table, uuid, statutHttp, corps, maintenant = () => new Date().toISOString()) {
    const issue = issueDeSynchro(statutHttp, corps);

    if (issue.etat === 'synchronise') {
        await table.update(uuid, { is_synced: SYNCHRONISEE });
    } else if (issue.etat === 'refuse') {
        await table.update(uuid, { is_synced: REFUSEE, refus_motif: issue.motif, refus_le: maintenant() });
    }

    return issue;
}
