/**
 * LE TERRAIN NE PROPOSE NI LOT NI BÂTIMENT VIRTUEL — COMME LE BUREAU.
 * Cf. ../src/offline/virtuels.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { estBatimentReel, estLotReel, ZONE_VIRTUELLE } from '../src/offline/virtuels.ts'

test('un lot de traçabilité (effectif initial nul) n\'est pas un lot réel', () => {
  assert.equal(estLotReel({ initial_quantity: 0, status: 'Actif' }), false)
  assert.equal(estLotReel({ initial_quantity: null, status: 'Actif' }), false)
})

test('un lot annulé sort des listes, comme au bureau (Batch::scopeLive)', () => {
  assert.equal(estLotReel({ initial_quantity: 500, status: 'Annulé' }), false)
})

test('un lot avec des animaux est proposé', () => {
  assert.equal(estLotReel({ initial_quantity: 500, status: 'Actif' }), true)
})

test('la zone virtuelle n\'est pas un bâtiment', () => {
  assert.equal(estBatimentReel({ name: ZONE_VIRTUELLE }), false)
  assert.equal(estBatimentReel({ name: 'Poulailler 1' }), true)
})
