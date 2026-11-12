/**
 * MON POSTE : LES GESTES DU MÉTIER, ET RIEN QUI MÈNE À « ACCÈS REFUSÉ ».
 * Cf. ../src/offline/postes.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { POSTES, ESPACES, CATALOGUE, LIBELLES_ESPACES, espaceDeduit, espaceDeLaSession, tuilesDuPoste, catalogueOuvert, espacesAffichables } from '../src/offline/postes.ts'
import { accessForPath, allows, ROUTE_ACCESS } from '../src/offline/access.ts'
import { en } from '../src/i18n/en.ts'

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

test('l’agent de terrain voit pointage, nourrir (brut), scan, soin, traite, relevé et ses tâches', () => {
  const tuiles = tuilesDuPoste('terrain', ouvrable(profil(AGENT_TERRAIN))).map((t) => t.to)
  assert.deepEqual(tuiles, ['/elevage/pointage', '/elevage/nourrir-brut', '/scan', '/elevage/soin', '/elevage/traite', '/ressources/releve', '/taches'])
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

test('« Tous mes écrans » : chaque écran qui s’ouvre sans paramètre y figure — rien n’est introuvable', () => {
  const auCatalogue = new Set(CATALOGUE.flatMap((r) => r.tuiles.map((t) => t.to)))
  const ouvrablesSeuls = Object.keys(ROUTE_ACCESS)
    .filter((chemin) => !/:[a-zA-Z]+(?!\?)(\/|$)/.test(chemin))   // pas de paramètre obligatoire
    .map((chemin) => chemin.replace(/\/:[a-zA-Z]+\?$/, ''))         // un paramètre facultatif s'omet
    .filter((chemin) => chemin !== '/menu')

  const absents = ouvrablesSeuls.filter((chemin) => !auCatalogue.has(chemin))
  assert.deepEqual(absents, [])
})

test('le catalogue se filtre par droits et par recherche, sans accents', () => {
  const vendeur = catalogueOuvert(ouvrable(profil(VENDEUR)))
  const chemins = vendeur.flatMap((r) => r.tuiles.map((t) => t.to))
  assert.ok(chemins.includes('/commerce/vente'))
  assert.ok(!chemins.includes('/elevage/pointage'))

  const recherche = catalogueOuvert(ouvrable(profil(AGENT_TERRAIN)), 'releve')
  assert.deepEqual(recherche.flatMap((r) => r.tuiles.map((t) => t.to)), ['/ressources/releve'])
})

test('un profil large peut changer de vue de poste ; un agent n’a que la sienne utile', () => {
  const direction = espacesAffichables(ouvrable(profil({ admin: 'LCMS', elevage: 'LCMS', commerce: 'LCMS', logistique: 'LCMS', rh: 'LCMS', tresorerie: 'LCMS', production: 'LCMS', ressources: 'LCMS' })), 'direction')
  assert.ok(direction.includes('vente') && direction.includes('terrain') && direction.length > 3)

  const vendeur = espacesAffichables(ouvrable(profil(VENDEUR)), 'vente')
  assert.ok(vendeur.includes('vente'))
  assert.ok(!vendeur.includes('terrain'))
  // La vue « comptable » ne lui apporterait que ses propres gestes : pas de choix à faire.
  assert.deepEqual(vendeur, ['vente'])

  // Le gérant peut regarder le poste d'un autre métier, et garde le sien.
  const gerant = espacesAffichables(ouvrable(profil({ elevage: 'LCM', production: 'LCM', commerce: 'LCM', logistique: 'LCM', abattoir: 'LCM', provenderie: 'LCM', cultures: 'LCM', ressources: 'LCM', rh: 'LCM', depenses: 'LCM', tresorerie: 'L' })), 'gerant')
  assert.ok(gerant.includes('gerant') && gerant.includes('abattoir') && gerant.includes('vente'))
})

test('les libellés des postes, du menu et des espaces sont traduits (données : le scan des t() ne les voit pas)', () => {
  const cles = new Set(Object.values(LIBELLES_ESPACES))
  for (const tuiles of Object.values(POSTES)) for (const tuile of tuiles) { cles.add(tuile.label); cles.add(tuile.hint) }
  for (const rubrique of CATALOGUE) {
    cles.add(rubrique.titre)
    for (const tuile of rubrique.tuiles) { cles.add(tuile.label); cles.add(tuile.hint) }
  }

  assert.deepEqual([...cles].filter((cle) => !(cle in en)), [])
  assert.deepEqual(Object.keys(LIBELLES_ESPACES).sort(), [...ESPACES].sort())
})

test('seul l’encadrement change de vue : le technicien d’élevage ne choisit pas le poste du magasinier', () => {
  // Même avec des droits de saisie sur le stock, son poste reste le sien.
  const technicien = espacesAffichables(ouvrable(profil({ elevage: 'LCM', production: 'LCM', logistique: 'LCM', ressources: 'LC' })), 'elevage')
  assert.deepEqual(technicien, ['elevage'])
})
