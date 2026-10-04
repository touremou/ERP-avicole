/**
 * LE MIROIR DE RÉFÉRENCE N'EST PAS HÉRITÉ PAR LE COMPTE SUIVANT.
 * Cf. ../src/offline/miroir.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { miroirAReconstruire } from '../src/offline/miroir.ts'

const A_KINDIA = { user_id: 7, farm_id: 1 }

test('même compte, même site : le delta suffit — pas de retéléchargement inutile', () => {
  assert.equal(miroirAReconstruire(A_KINDIA, { user_id: 7, farm_id: 1 }), false)
})

test('un AUTRE compte reconstruit le miroir — il n’hérite pas de ce qu’il n’a pas le droit de voir', () => {
  assert.equal(miroirAReconstruire(A_KINDIA, { user_id: 8, farm_id: 1 }), true)
})

test('un AUTRE site reconstruit le miroir — deux fermes ne se mélangent pas', () => {
  assert.equal(miroirAReconstruire(A_KINDIA, { user_id: 7, farm_id: 2 }), true)
})

test('provenance inconnue (versions antérieures) : on reconstruit une fois', () => {
  assert.equal(miroirAReconstruire(undefined, A_KINDIA), true)
})

test('site non attribué des deux côtés : même propriétaire', () => {
  assert.equal(miroirAReconstruire({ user_id: 7, farm_id: null }, { user_id: 7, farm_id: null }), false)
})
