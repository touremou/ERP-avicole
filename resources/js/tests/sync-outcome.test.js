/**
 * QUE DEVIENT UNE SAISIE HORS-LIGNE, SELON LA RÉPONSE DU SERVEUR ?
 *
 * Lancé par `node --test 'resources/js/tests/*.test.js'` — sans dépendance, en CI comme en
 * local. Le moteur web perdait des saisies de trois façons (cf.
 * `../sync-outcome.js`) ; chacune a ici son test, et chacune échouait sur
 * l'ancien comportement.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { issueDeSynchro } from '../sync-outcome.js';

test('succès et rejeu : la saisie est synchronisée', () => {
    assert.equal(issueDeSynchro(200, { status: 'success' }).etat, 'synchronise');
    assert.equal(issueDeSynchro(200, { status: 'already_synced' }).etat, 'synchronise');
});

test('un REFUS MÉTIER (conflict) est gardé, avec son motif — il ne disparaît plus', () => {
    // LE défaut n°1 : marqué « synchronisé », il disparaissait sans trace visible.
    const issue = issueDeSynchro(200, { status: 'conflict', message: 'Stock insuffisant (disponible : 4 kg).' });

    assert.equal(issue.etat, 'refuse');
    assert.equal(issue.motif, 'Stock insuffisant (disponible : 4 kg).');
});

test('un 2xx ne suffit pas : le CORPS décide', () => {
    // LE défaut n°2 : les pointages étaient validés sur le seul `response.ok`.
    assert.equal(issueDeSynchro(200, { status: 'conflict', message: 'Jour déjà pointé.' }).etat, 'refuse');
});

test('données invalides (422) : refus définitif, motif du premier champ', () => {
    // LE défaut n°3 : renvoyée à chaque reconnexion, indéfiniment.
    const issue = issueDeSynchro(422, { message: 'Données invalides.', errors: { quantity: ['La quantité doit être positive.'] } });

    assert.equal(issue.etat, 'refuse');
    assert.equal(issue.motif, 'La quantité doit être positive.');
});

test('droit retiré (403) : refus définitif', () => {
    assert.equal(issueDeSynchro(403, { message: 'Permission insuffisante.' }).etat, 'refuse');
});

test('réseau tombé, serveur en panne, surcharge : on RÉESSAIERA — la borne', () => {
    /*
     * LA borne : une panne passagère n'est pas un refus. Classer un 503 en
     * refus ferait perdre des saisies parfaitement valides.
     */
    for (const statut of [0, 500, 502, 503, 408, 429]) {
        assert.equal(issueDeSynchro(statut, {}).etat, 'reessayer', `HTTP ${statut}`);
    }
    assert.equal(issueDeSynchro(200, { status: 'error' }).etat, 'reessayer');
});

test('session expirée (401, 419) : on réessaiera après reconnexion — la saisie n’est pas en cause', () => {
    assert.equal(issueDeSynchro(401, {}).etat, 'reessayer');
    assert.equal(issueDeSynchro(419, {}).etat, 'reessayer');
});

test('ancien contrat sans statut dans le corps : le 2xx fait foi — non-régression', () => {
    assert.equal(issueDeSynchro(200, {}).etat, 'synchronise');
    assert.equal(issueDeSynchro(201, null).etat, 'synchronise');
});

// ─── Ce que le moteur ÉCRIT dans la file locale ───

import { rangerSaisie, SYNCHRONISEE, REFUSEE } from '../sync-outcome.js';

/** Un double de table Dexie : il retient ce qu'on y écrit. */
function tableTemoin() {
    const ecrit = {};
    return { ecrit, update: async (uuid, champs) => { ecrit[uuid] = { ...(ecrit[uuid] ?? {}), ...champs }; } };
}

test('une saisie refusée n’est JAMAIS écrite « synchronisée »', async () => {
    // L'écriture même qui faisait disparaître les saisies.
    const table = tableTemoin();

    await rangerSaisie(table, 'u1', 200, { status: 'conflict', message: 'Collecte déjà triée.' }, () => 'T');

    assert.deepEqual(table.ecrit.u1, { is_synced: REFUSEE, refus_motif: 'Collecte déjà triée.', refus_le: 'T' });
});

test('une saisie acceptée est écrite synchronisée, sans motif', async () => {
    const table = tableTemoin();

    await rangerSaisie(table, 'u2', 200, { status: 'success' });

    assert.deepEqual(table.ecrit.u2, { is_synced: SYNCHRONISEE });
});

test('une panne n’ÉCRIT RIEN : la saisie reste en attente, donc sera renvoyée', async () => {
    const table = tableTemoin();

    await rangerSaisie(table, 'u3', 503, {});
    await rangerSaisie(table, 'u3', 0, {});

    assert.equal(table.ecrit.u3, undefined);
});
