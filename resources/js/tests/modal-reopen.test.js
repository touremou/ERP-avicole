import { test } from 'node:test';
import assert from 'node:assert/strict';
import { champsARestaurer, actionSure } from '../modal-reopen.js';

test('les champs saisis sont restaurés, pas le jeton, la méthode ni un mot de passe', () => {
    const champs = champsARestaurer({
        _token: 'x', _method: 'PUT', _modale: 'modalLabo', password: 's3cret',
        energy_kcal: '3300', protein_rate: '8', notes: 'Analyse labo',
    });
    assert.deepEqual(champs.map(([n]) => n), ['energy_kcal', 'protein_rate', 'notes']);
});

test('les tableaux (cases multiples) ne sont pas recopiés dans un champ texte', () => {
    assert.deepEqual(champsARestaurer({ tags: ['a', 'b'], nom: 'Maïs' }), [['nom', 'Maïs']]);
});

test('l’adresse du formulaire n’est restaurée que sur ce site', () => {
    const ici = 'https://ferme.test';
    assert.equal(actionSure('https://ferme.test/provenderie/materials/4/nutrition', ici), 'https://ferme.test/provenderie/materials/4/nutrition');
    assert.equal(actionSure('/provenderie/materials/4/nutrition', ici), 'https://ferme.test/provenderie/materials/4/nutrition');
    assert.equal(actionSure('https://ailleurs.test/vol', ici), null);
    assert.equal(actionSure('', ici), null);
});
