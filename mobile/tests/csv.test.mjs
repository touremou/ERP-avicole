/**
 * LES EXPORTS DU TERRAIN NE PORTENT PLUS DE FORMULE.
 * Même règle que le serveur (app/Support/CsvExport.php), cf. ../src/ui/exportShare.ts.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { toCsv, neutraliser } from '../src/ui/exportShare.ts'

test('un texte qui commence comme une formule est neutralisé', () => {
  for (const piege of ['=HYPERLINK("http://x","clic")', '+33 1', '-20 % remise', '@SUM(A1)', '\tx', '\rx']) {
    assert.ok(neutraliser(piege).startsWith("'"), JSON.stringify(piege))
  }
})

test('un NOMBRE reste un nombre — même négatif : les totaux du tableur tiennent', () => {
  assert.equal(neutraliser(-1500), '-1500')
  assert.equal(neutraliser(42.5), '42.5')
})

test('un texte ordinaire n’est pas touché — non-régression', () => {
  assert.equal(neutraliser('Poulet chair'), 'Poulet chair')
  assert.equal(neutraliser(null), '')
})

test('dans le fichier, la cellule piégée arrive inerte', () => {
  const csv = toCsv(['Client', 'Montant'], [['=HYPERLINK("http://x";"clic")', 5000]])
  const ligne = csv.split('\r\n')[1]
  assert.equal(ligne, `"'=HYPERLINK(""http://x"";""clic"")";5000`)
})
