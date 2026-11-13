/**
 * LA MISE EN LOT NE PROPOSE QUE LES ESPÈCES ACTIVÉES, REGROUPÉES PAR ESPÈCE.
 * Cf. ../src/offline/miseEnLot.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { typesParEspece } from '../src/offline/miseEnLot.ts'

const type = (id, name_fr, species_name, utilisable) => ({ id, slug: name_fr, name_fr, species_name, utilisable, updated_at: '' })

test('un type d’une espèce désactivée n’est pas proposé', () => {
  const groupes = typesParEspece([type(1, 'Chair', 'Poulet', true), type(2, 'Engraissement', 'Porc', false)])
  assert.deepEqual(groupes.map((g) => g.espece), ['Poulet'])
})

test('les types sont regroupés sous leur espèce', () => {
  const groupes = typesParEspece([
    type(1, 'Ponte', 'Poulet', true), type(2, 'Embouche', 'Mouton', true), type(3, 'Chair', 'Poulet', true),
  ])
  assert.deepEqual(groupes.map((g) => [g.espece, g.types.map((t) => t.name_fr)]), [
    ['Mouton', ['Embouche']], ['Poulet', ['Chair', 'Ponte']],
  ])
})

test('un serveur antérieur (sans « utilisable ») ne vide pas la liste', () => {
  assert.equal(typesParEspece([type(1, 'Chair', undefined, undefined)]).length, 1)
})
