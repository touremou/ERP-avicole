/**
 * LE TÉLÉPHONE REFUSE CE QUE LE SERVEUR REFUSERA.
 * Cf. ../src/offline/opRules.ts et Batch::refusDeSaisie / RecordEggCollection.
 *
 * Une saisie que le serveur rejette n'y arrive qu'à la synchro, parfois des
 * heures plus tard, et tombe au bac « À corriger ». Le miroir la refuse à la
 * saisie, quand l'opérateur a encore le lot devant lui.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { validateOp } from '../src/offline/opRules.ts'

const demain = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10)
const aujourdhui = new Date().toISOString().slice(0, 10)

test('un pointage daté de demain est refusé à la saisie', () => {
  assert.notEqual(validateOp('daily_check.create', { batch_id: 3, check_date: demain }).length, 0)
})

test('le pointage du jour passe — non-régression', () => {
  assert.deepEqual(validateOp('daily_check.create', { batch_id: 3, check_date: aujourdhui, mortality: 2 }), [])
})

test('plus de cassés et de petits que d’œufs ramassés est refusé', () => {
  const erreurs = validateOp('egg_collection.create', {
    batch_id: 3, production_date: aujourdhui, total_eggs_collected: 10, broken_eggs: 8, small_eggs: 5,
  })
  assert.notEqual(erreurs.length, 0)
})

test('une collecte cohérente passe — non-régression', () => {
  assert.deepEqual(validateOp('egg_collection.create', {
    batch_id: 3, production_date: aujourdhui, total_eggs_collected: 80, broken_eggs: 2, small_eggs: 3,
  }), [])
})
