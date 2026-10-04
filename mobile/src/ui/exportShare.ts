/**
 * exportShare — export CSV des journaux avec PARTAGE natif (Web Share API,
 * fichier) et repli TÉLÉCHARGEMENT. Aucune dépendance : côté PWA on privilégie
 * le partage système (WhatsApp, e-mail, Drive…) quand il est disponible.
 */

/**
 * Caractères qui font d'une cellule une FORMULE dans un tableur — la même
 * liste que le serveur (app/Support/CsvExport.php).
 *
 * Le serveur neutralisait déjà ses exports ; ceux du terrain, non. Or ces
 * journaux portent des textes saisis par n'importe quel utilisateur (nom de
 * client, libellé d'article, description d'écriture) : « =HYPERLINK(…) » en
 * tête s'exécutait à l'ouverture dans Excel ou LibreOffice, chez le
 * gestionnaire à qui le technicien partage le fichier. Un libellé légitime
 * (« -20 % remise ») déclenche la même chose.
 */
const PREFIXES_DANGEREUX = ['=', '+', '-', '@', '\t', '\r']

/**
 * Rend une cellule inoffensive sans la déformer : seul un TEXTE commençant
 * par un préfixe dangereux reçoit une apostrophe. Les nombres restent des
 * nombres — les préfixer casserait les totaux de la feuille.
 */
export function neutraliser(value: string | number | null | undefined): string {
  if (typeof value === 'number') return String(value)
  const s = String(value ?? '')
  return PREFIXES_DANGEREUX.some((p) => s.startsWith(p)) ? "'" + s : s
}

/** Construit un CSV (séparateur « ; », BOM UTF-8 ajouté à l'export). */
export function toCsv(headers: string[], rows: (string | number | null | undefined)[][]): string {
  const escape = (value: string | number | null | undefined): string => {
    const s = neutraliser(value)
    return /[";\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s
  }
  return [headers, ...rows].map((row) => row.map(escape).join(';')).join('\r\n')
}

/**
 * Partage le CSV en tant que fichier (si l'appareil le permet), sinon le
 * télécharge. `title` sert de sujet au partage natif.
 */
export async function exportOrShare(filename: string, csv: string, title: string): Promise<void> {
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const file = new File([blob], filename, { type: 'text/csv' })

  if (navigator.canShare && navigator.canShare({ files: [file] }) && navigator.share) {
    try {
      await navigator.share({ files: [file], title })
      return
    } catch {
      // Partage annulé ou impossible → repli sur le téléchargement.
    }
  }

  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

/** Suffixe de date lisible pour les noms de fichier (YYYY-MM-DD). */
export function dateStamp(): string {
  return new Date().toISOString().slice(0, 10)
}
