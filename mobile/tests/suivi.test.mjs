/**
 * Suivi par espèce — fonctions pures (offline/suivi.ts).
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { suiviPour, rubriqueVisible, produitsProposes, SUIVI_NEUTRE } from '../src/offline/suivi.ts'

const REFERENTIEL = {
  defaut: { symptomes: ['Prostration'], produits: {}, modes: ['Injection'], masquer: ['picage'] },
  volaille: { symptomes: ['Chute de ponte'], produits: { Vaccin: ['Gumboro'] }, modes: ['Eau de boisson', 'Oculaire'], masquer: [] },
  petit_ruminant: { symptomes: ['Ballonnement'], produits: { Vaccin: ['PPR'] }, modes: ['Injection', 'Voie orale'], masquer: ['picage', 'litiere'] },
}

test('chaque lot reçoit le suivi de SA famille', () => {
  assert.deepEqual(suiviPour(REFERENTIEL, 'petit_ruminant').symptomes, ['Ballonnement'])
  assert.ok(!suiviPour(REFERENTIEL, 'petit_ruminant').modes.includes('Oculaire'))
  assert.deepEqual(produitsProposes(suiviPour(REFERENTIEL, 'volaille'), 'Vaccin'), ['Gumboro'])
  assert.deepEqual(produitsProposes(suiviPour(REFERENTIEL, 'volaille'), 'Désinfection'), [])
})

test('le pointage masque ce qui n’a pas de sens pour l’animal', () => {
  const mouton = suiviPour(REFERENTIEL, 'petit_ruminant')
  assert.equal(rubriqueVisible(mouton, 'picage'), false)
  assert.equal(rubriqueVisible(mouton, 'litiere'), false)
  assert.equal(rubriqueVisible(mouton, 'boiteux'), true)
  assert.equal(rubriqueVisible(suiviPour(REFERENTIEL, 'volaille'), 'picage'), true)
})

test('famille inconnue ou serveur ancien : un suivi neutre, jamais celui de la volaille', () => {
  assert.deepEqual(suiviPour(REFERENTIEL, 'escargot'), REFERENTIEL.defaut)
  assert.deepEqual(suiviPour(REFERENTIEL, null), REFERENTIEL.defaut)
  assert.deepEqual(suiviPour(undefined, 'volaille'), SUIVI_NEUTRE)
  assert.ok(!SUIVI_NEUTRE.symptomes.includes('Chute de ponte'))
})
