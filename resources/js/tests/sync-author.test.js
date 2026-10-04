/**
 * UN NAVIGATEUR PARTAGÉ NE DOIT PAS ATTRIBUER UNE SAISIE AU MAUVAIS COMPTE,
 * NI LA DÉPOSER SUR LE MAUVAIS SITE. Cf. `poussableIci` dans ../sync-outcome.js.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { poussableIci, corpsAEnvoyer, contexteCourant } from '../sync-outcome.js';

const ICI = { auteur_id: 7, ferme_id: 2 };

test('la saisie du compte connecté, sur son site, part', () => {
    assert.equal(poussableIci({ auteur_id: 7, ferme_id: 2 }, ICI), true);
});

test('la saisie d’un AUTRE compte reste en file — elle ne part pas sous la session suivante', () => {
    assert.equal(poussableIci({ auteur_id: 9, ferme_id: 2 }, ICI), false);
});

test('la saisie faite sur un AUTRE site reste en file — elle n’atterrit pas sur le site actif', () => {
    assert.equal(poussableIci({ auteur_id: 7, ferme_id: 3 }, ICI), false);
});

test('une saisie sans marque (antérieure au correctif) part — non-régression', () => {
    assert.equal(poussableIci({}, ICI), true);
});

test('personne de connecté : rien ne part', () => {
    // Même une saisie sans marque : la page de connexion charge aussi le moteur.
    assert.equal(poussableIci({}, { auteur_id: null, ferme_id: null }), false);
    assert.equal(poussableIci({ auteur_id: 7 }, { auteur_id: null, ferme_id: null }), false);
});

test('les marques du navigateur ne partent pas au serveur', () => {
    const corps = corpsAEnvoyer({ uuid: 'u', amount: 5, auteur_id: 7, ferme_id: 2, refus_motif: 'x', refus_le: 't', is_synced: 0 });
    assert.deepEqual(corps, { uuid: 'u', amount: 5, is_synced: 0 });
});

test('le contexte se lit dans les balises de la page, en nombres', () => {
    const doc = { querySelector: (sel) => ({
        'meta[name="avismart-user"]': { getAttribute: () => '7' },
        'meta[name="avismart-farm"]': { getAttribute: () => '' },
    })[sel] ?? null };
    assert.deepEqual(contexteCourant(doc), { auteur_id: 7, ferme_id: null });
});
