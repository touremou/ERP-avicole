import { test } from 'node:test';
import assert from 'node:assert/strict';
import { jugerRatio, lectureEleveur } from '../breeding-ratio.js';

const mouton = { min: 2.5, max: 4, male: 'bélier', femelles: 'brebis' };
const poulet = { min: 8, max: 12, male: 'coq', femelles: 'poules' };

test('4 béliers pour 10 brebis : beaucoup trop de mâles (signalé de l’écran)', () => {
    const v = jugerRatio(4, 10, mouton);
    assert.equal(v.etat, 'trop');
    assert.equal(v.pourcentage, 40);
    assert.match(v.conseil, /Référence : 1 bélier pour 25 à 40 brebis/);
    assert.match(v.conseil, /pour 10 brebis : 1 bélier\(s\)/);
});

test('1 bélier pour 30 brebis : conforme (la norme du poulet le disait insuffisant)', () => {
    assert.equal(jugerRatio(1, 30, mouton).etat, 'ok');
    assert.equal(jugerRatio(1, 30, poulet).etat, 'peu');
});

test('10 coqs pour 100 poules : conforme', () => {
    assert.equal(jugerRatio(10, 100, poulet).etat, 'ok');
});

test('sans femelle : en attente ; sans mâle : signalé', () => {
    assert.equal(jugerRatio(3, 0, poulet).etat, 'attente');
    assert.equal(jugerRatio(0, 50, poulet).etat, 'aucun_male');
});

test('une espèce sans référence ne reçoit pas celle d’une autre', () => {
    assert.equal(jugerRatio(2, 10, null).etat, 'inconnu');
});

test('lecture d’éleveur : « 1 bélier pour 2,5 brebis »', () => {
    assert.equal(lectureEleveur(4, 10, mouton), '1 bélier pour 2,5 brebis');
    assert.equal(lectureEleveur(0, 10, mouton), '');
});

test('traduire : une clé courte ne mord pas dans une clé plus longue', async () => {
    const { traduire } = await import('../sync-outcome.js');
    assert.equal(traduire(':f :femelles', { f: 10, femelles: 'brebis' }), '10 brebis');
});

test('1 bélier pour 19 brebis : conforme — on ne peut pas avoir moins d’un mâle', () => {
    // Signalé : « Trop de mâles », conseil « 1 bélier pour 19 brebis » = la saisie.
    assert.equal(jugerRatio(1, 19, mouton).etat, 'ok');
});

test('le conseil donne la RÉFÉRENCE de l’espèce et le nombre de mâles, pas la saisie', () => {
    const v = jugerRatio(1, 100, mouton);
    assert.equal(v.etat, 'peu');
    assert.match(v.conseil, /Référence : 1 bélier pour 25 à 40 brebis/);
    assert.match(v.conseil, /pour 100 brebis : 3 à 4 bélier\(s\)/);
});

test('poulet : 8 à 12 coqs pour 100 poules (1 coq pour 8,3 à 12,5 poules)', async () => {
    const { malesConseilles } = await import('../breeding-ratio.js');
    assert.deepEqual(malesConseilles(100, poulet), { minMales: 8, maxMales: 12 });
    assert.equal(jugerRatio(10, 100, poulet).etat, 'ok');
    assert.equal(jugerRatio(13, 100, poulet).etat, 'trop');
});
