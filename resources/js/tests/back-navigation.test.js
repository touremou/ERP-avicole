import { test } from 'node:test';
import assert from 'node:assert/strict';
import { retourParHistorique } from '../back-navigation.js';

const ici = new URL('https://ferme.test/daily-checks/create?batch_id=4');

test('venu de la fiche du lot : la flèche y revient', () => {
    assert.equal(retourParHistorique('https://ferme.test/batches/4', ici, 3), true);
});

test('venu d’une liste filtrée : la flèche y revient, filtres compris', () => {
    const fiche = new URL('https://ferme.test/batches/4');
    assert.equal(retourParHistorique('https://ferme.test/batches?status=Actif', fiche, 3), true);
});

test('après un enregistrement, on ne revient pas sur le formulaire', () => {
    const fiche = new URL('https://ferme.test/batches/4');
    assert.equal(retourParHistorique('https://ferme.test/batches/4/edit', fiche, 4), false);
    assert.equal(retourParHistorique('https://ferme.test/batches/create', fiche, 4), false);
});

test('la même page rechargée après une action : lien de repli', () => {
    const vente = new URL('https://ferme.test/sales/12');
    assert.equal(retourParHistorique('https://ferme.test/sales/12', vente, 5), false);
});

test('onglet ouvert directement, ou venu d’ailleurs : lien de repli', () => {
    assert.equal(retourParHistorique('', ici, 1), false);
    assert.equal(retourParHistorique('https://ferme.test/batches/4', ici, 1), false);
    assert.equal(retourParHistorique('https://google.com/search?q=x', ici, 3), false);
});

test('on ne revient jamais sur la connexion', () => {
    assert.equal(retourParHistorique('https://ferme.test/login', ici, 3), false);
});

test('un référent illisible n’est pas une page où revenir', () => {
    assert.equal(retourParHistorique('pas une url', ici, 3), false);
});
