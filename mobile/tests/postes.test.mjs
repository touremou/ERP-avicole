/**
 * MON POSTE : LES GESTES DU MÉTIER, ET RIEN QUI MÈNE À « ACCÈS REFUSÉ ».
 * Cf. ../src/offline/postes.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { POSTES, ESPACES, espaceDeduit, espaceDeLaSession, tuilesDuPoste } from '../src/offline/postes.ts'
import { accessForPath, allows } from '../src/offline/access.ts'

/** Le contrôle des routes, comme l'écran le passe. */
const ouvrable = (ctx) => (chemin) => {
  const spec = accessForPath(chemin)
  return spec !== null && allows(spec, ctx)
}

/** Un profil de droits : { elevage: 'LC', ... } */
const profil = (droits, employe = true) => ({
  can: (module, niveau) => (droits[module] ?? '').includes(niveau),
  hasEmployee: employe,
})

const AGENT_TERRAIN = { elevage: 'LC', production: 'LC', ressources: 'LC', logistique: 'L' }
const VENDEUR = { commerce: 'LC', caisse: 'LCM', annuaire: 'LC', logistique: 'L' }

test('chaque tuile de chaque poste mène à un écran qui existe', () => {
  for (const espace of ESPACES) {
    for (const tuile of POSTES[espace]) {
      assert.notEqual(accessForPath(tuile.to), null, `${espace} → ${tuile.to}`)
    }
  }
})

test('l’agent de terrain voit pointage, scan, soin, traite, relevé et ses tâches', () => {
  const tuiles = tuilesDuPoste('terrain', ouvrable(profil(AGENT_TERRAIN))).map((t) => t.to)
  assert.deepEqual(tuiles, ['/elevage/pointage', '/scan', '/elevage/soin', '/elevage/traite', '/ressources/releve', '/taches'])
})

test('une tuile que le profil ne peut pas ouvrir n’apparaît pas', () => {
  // Sans fiche employé, pas de « Mes tâches » ; sans ressources, pas de relevé.
  const tuiles = tuilesDuPoste('terrain', ouvrable(profil({ elevage: 'LC', production: 'LC' }, false))).map((t) => t.to)
  assert.ok(!tuiles.includes('/taches'))
  assert.ok(!tuiles.includes('/ressources/releve'))

  // Le vendeur n'encaisse pas sans le droit de saisie commerce.
  const lecteur = tuilesDuPoste('vente', ouvrable(profil({ commerce: 'L', logistique: 'L' }))).map((t) => t.to)
  assert.deepEqual(lecteur, ['/commerce/journal', '/logistique/stocks'])
})

test('l’espace vient du serveur, sinon il se déduit des droits (miroir de ProfilsMetier)', () => {
  assert.equal(espaceDeLaSession('abattoir', profil(VENDEUR).can), 'abattoir')
  assert.equal(espaceDeLaSession(undefined, profil(VENDEUR).can), 'vente')
  assert.equal(espaceDeLaSession('inconnu', profil(AGENT_TERRAIN).can), 'terrain')

  assert.equal(espaceDeduit(profil({ admin: 'LCMS' }).can), 'direction')
  assert.equal(espaceDeduit(profil({ elevage: 'LCM', production: 'LCM' }).can), 'elevage')
  const partout = { elevage: 'LC', production: 'LC', abattoir: 'LC', commerce: 'LC', logistique: 'LC' }
  assert.equal(espaceDeduit(profil(partout).can), 'gerant')
})
