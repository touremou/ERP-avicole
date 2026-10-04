/**
 * CHAQUE SAISIE PART SOUS L'EN-TÊTE DE SON SITE.
 *
 * Premiers tests de l'application terrain, sans dépendance :
 *   node --experimental-strip-types --test 'mobile/tests/*.test.mjs'
 * Cf. `lotsParSite` (../src/offline/lots.ts) et OfflineEntryLandsOnItsOwnFarmTest.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { lotsParSite } from '../src/offline/lots.ts'

const op = (id, farm_id) => ({ op_uuid: id, farm_id })

test('un lot ne mélange JAMAIS deux sites', () => {
  // Saisies à Kindia (1), bascule vers Kérouané (2), retour à Kindia.
  const lots = lotsParSite([op('a', 1), op('b', 2), op('c', 1)], 50)

  for (const lot of lots) {
    assert.equal(new Set(lot.map((e) => e.farm_id)).size, 1, JSON.stringify(lot))
  }
  assert.deepEqual(lots.map((l) => l.map((e) => e.op_uuid)), [['a', 'c'], ['b']])
})

test('l’ordre de saisie est gardé au sein d’un site', () => {
  const lots = lotsParSite([op('1', 1), op('2', 1), op('3', 1)], 50)
  assert.deepEqual(lots[0].map((e) => e.op_uuid), ['1', '2', '3'])
})

test('les saisies sans site (antérieures) forment leur propre lot — non-régression', () => {
  const lots = lotsParSite([op('a', undefined), op('b', 1), op('c', undefined)], 50)
  assert.deepEqual(lots.map((l) => l.map((e) => e.op_uuid)), [['a', 'c'], ['b']])
})

test('la taille de lot reste bornée (le serveur accepte 100 au plus)', () => {
  const file = Array.from({ length: 120 }, (_, i) => op(String(i), 1))
  const lots = lotsParSite(file, 50)
  assert.deepEqual(lots.map((l) => l.length), [50, 50, 20])
})
