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
