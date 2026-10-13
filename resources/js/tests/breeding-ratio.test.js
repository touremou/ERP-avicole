import { test } from 'node:test';
import assert from 'node:assert/strict';
import { jugerRatio, lectureEleveur } from '../breeding-ratio.js';

const mouton = { min: 2.5, max: 4, male: 'bélier', femelles: 'brebis' };
const poulet = { min: 8, max: 12, male: 'coq', femelles: 'poules' };

test('4 béliers pour 10 brebis : beaucoup trop de mâles (signalé de l’écran)', () => {
    const v = jugerRatio(4, 10, mouton);
    assert.equal(v.etat, 'trop');
    assert.equal(v.pourcentage, 40);
    assert.match(v.conseil, /1 bélier\(s\) pour 10 brebis/);
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
