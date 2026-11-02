/**
 * UN COÛT S'IMPUTE À UNE BANDE ACTIVE OU SOLDÉE — AU CHAMP COMME AU BUREAU.
 * Cf. ../src/offline/statutsDeLot.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { accepteImputation, STATUTS_IMPUTABLES } from '../src/offline/statutsDeLot.ts'

test('une bande soldée reçoit encore ses derniers coûts', () => {
  assert.equal(accepteImputation('Soldé'), true)
  assert.equal(accepteImputation('Actif'), true)
})

test('une bande clôturée, avortée ou annulée ne reçoit plus rien', () => {
  for (const statut of ['Terminé', 'Avorté', 'Annulé', '', null, undefined]) {
    assert.equal(accepteImputation(statut), false, String(statut))
  }
})

test('la liste des statuts imputables est celle du serveur (Batch::accepteImputation)', () => {
  assert.deepEqual(STATUTS_IMPUTABLES, ['Actif', 'Soldé'])
})

test('les événements d’élevage ne se consignent que sur une bande active, comme au bureau', async () => {
  const { accepteEvenementDElevage, motifSansEvenement } = await import('../src/offline/statutsDeLot.ts')
  assert.equal(accepteEvenementDElevage('Actif'), true)
  for (const statut of ['Soldé', 'Terminé', 'Avorté', 'Annulé']) {
    assert.equal(accepteEvenementDElevage(statut), false, statut)
    assert.ok(motifSansEvenement(statut))
  }
  assert.match(motifSansEvenement('Soldé'), /soldé/)
  assert.equal(motifSansEvenement('Actif'), null)
})
