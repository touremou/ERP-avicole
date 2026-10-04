/**
 * AUCUN TEXTE DE L'APPLICATION TERRAIN SANS TRADUCTION ANGLAISE.
 *
 * Le dictionnaire (src/i18n/en.ts) avait été « généré depuis les appels t() »
 * une fois ; les écrans ajoutés depuis — mise en lot, affectation de tâche,
 * clôture d'abattoir… — affichaient 63 textes en français à un utilisateur
 * réglé en anglais. Ce test lit le VRAI dictionnaire (pas une analyse du
 * fichier) et refuse tout appel `t('…')` littéral qu'il ne couvre pas.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { en } from '../src/i18n/en.ts'

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', 'src')

function fichiers(dir) {
  return readdirSync(dir).flatMap((nom) => {
    const p = join(dir, nom)
    if (statSync(p).isDirectory()) return fichiers(p)
    return /\.(ts|tsx)$/.test(nom) && nom !== 'en.ts' ? [p] : []
  })
}

/** Littéral JS → chaîne, échappements compris (‍, \', \n…). */
const lire = (guillemet, corps) =>
  guillemet === '"' ? JSON.parse(`"${corps}"`) : JSON.parse(`"${corps.replace(/\\'/g, "'").replace(/"/g, '\\"')}"`)

test('chaque t(\'…\') littéral a sa traduction anglaise', () => {
  const manquants = []
  const appel = /\bt\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")\s*[,)]/g

  for (const fichier of fichiers(SRC)) {
    for (const m of readFileSync(fichier, 'utf8').matchAll(appel)) {
      const cle = m[1] !== undefined ? lire("'", m[1]) : lire('"', m[2])
      if (!(cle in en)) manquants.push(`${fichier.slice(SRC.length + 1)} : ${cle}`)
    }
  }

  assert.deepEqual(manquants, [])
})
