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
                return { etat: 'refuse', motif: body.message || 'Refusée par le serveur.' };
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
        return { etat: 'refuse', motif: body.message || 'Droit insuffisant pour enregistrer cette saisie.' };
    }

    if (statutHttp === 422) {
        const premiere = Object.values(body.errors ?? {}).flat()[0];
        return { etat: 'refuse', motif: premiere || body.message || 'Données invalides.' };
    }

    // Tout autre 4xx est un refus définitif : le renvoyer ne le changera pas.
    return { etat: 'refuse', motif: body.message || `Refusée par le serveur (HTTP ${statutHttp}).` };
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
