/**
 * CHAQUE LISTE DU TERRAIN NE PROPOSE QUE CE QUE LE SERVEUR ACCEPTE.
 * Cf. ../src/offline/eligibilite.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  alimentAccepte, batimentAccueille, cycleArchive, fournisseurActif, machineOperationnelle, surfaceLibre,
} from '../src/offline/eligibilite.ts'

test('mise en lot : un poulailler n’accueille pas des moutons, un bâtiment mixte accueille tout', () => {
  const embouche = { building_types: ['mixte', 'bergerie'] }
  assert.equal(batimentAccueille({ type: 'chair' }, embouche), false)
  assert.equal(batimentAccueille({ type: 'bergerie' }, embouche), true)
  assert.equal(batimentAccueille({ type: 'mixte' }, embouche), true)
  // Aucun type choisi, ou référentiel ancien : on ne masque rien.
  assert.equal(batimentAccueille({ type: 'chair' }, undefined), true)
})

test('pointage : « Ponte 1 » n’est pas proposé à un lot de chair, un aliment maison l’est', () => {
  const standard = ['Chair Démarrage', 'Chair Croissance', 'Ponte 1 (Pic de ponte)']
  const chair = { feed_phases: ['Chair Démarrage', 'Chair Croissance'] }
  assert.equal(alimentAccepte('Ponte 1 (Pic de ponte)', chair, standard), false)
  assert.equal(alimentAccepte('Chair Croissance', chair, standard), true)
  assert.equal(alimentAccepte('Son de riz maison', chair, standard), true)
})

test('fournisseurs, machines, cycles : la liste du bureau', () => {
  assert.equal(fournisseurActif({ status: 'Inactif' }), false)
  assert.equal(fournisseurActif({ status: 'Actif' }), true)
  assert.equal(machineOperationnelle({ status: 'En Panne' }), false)
  assert.equal(machineOperationnelle({ status: 'Opérationnel' }), true)
  assert.equal(cycleArchive('termine'), true)
  assert.equal(cycleArchive('abandonne'), true)
  assert.equal(cycleArchive('recolte'), false)
})

test('semis : une parcelle entièrement occupée n’a plus de surface libre', () => {
  const cycles = [{ plot_id: 1, status: 'en_cours', area_used_ha: 2 }, { plot_id: 1, status: 'termine', area_used_ha: 1 }]
  assert.equal(surfaceLibre({ id: 1, area_ha: 2 }, cycles), 0)
  assert.equal(surfaceLibre({ id: 1, area_ha: '3' }, cycles), 1)
})
