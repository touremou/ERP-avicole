/**
 * RENDU D'UN MESSAGE D'ALERTE — écrit en syntaxe WhatsApp.
 *
 * Les alertes et le résumé quotidien sont composés côté serveur pour WhatsApp :
 * titres de section en `*gras*`, une donnée par ligne, sous-lignes indentées de
 * deux espaces. Le centre d'alertes les affichait dans un simple `<span>` : les
 * sauts de ligne s'effondraient et les astérisques restaient visibles.
 *
 * On rend la MÊME chaîne, structurée. Pas de `dangerouslySetInnerHTML` : le
 * message contient des noms saisis par des humains (un lot, un bâtiment), et on
 * ne les fait pas passer par un analyseur HTML pour obtenir du gras.
 *
 * Le pendant web est `notif_html()` (app/Helpers/helpers.php) — mêmes règles,
 * pour que les deux écrans lisent le même message de la même façon.
 */

/** Découpe une ligne en segments, en gras entre astérisques. */
function segments(ligne: string): Array<{ texte: string; gras: boolean }> {
  const out: Array<{ texte: string; gras: boolean }> = []
  const re = /\*([^*]+)\*/g
  let curseur = 0
  let m: RegExpExecArray | null

  while ((m = re.exec(ligne)) !== null) {
    if (m.index > curseur) out.push({ texte: ligne.slice(curseur, m.index), gras: false })
    out.push({ texte: m[1], gras: true })
    curseur = m.index + m[0].length
  }

  if (curseur < ligne.length) out.push({ texte: ligne.slice(curseur), gras: false })

  return out
}

export function NotifMessage({ message }: { message?: string | null }) {
  const lignes = (message ?? '').split(/\r\n|\r|\n/)

  return (
    <span className="notif-message">
      {lignes.map((ligne, i) => {
        if (ligne.trim() === '') return <span key={i} className="notif-message-gap" />

        // L'indentation WhatsApp (deux espaces) marque une sous-ligne.
        const indente = ligne.startsWith('  ')

        return (
          <span key={i} className={indente ? 'notif-message-line notif-message-sub' : 'notif-message-line'}>
            {segments(ligne.trim()).map((s, j) => (s.gras ? <strong key={j}>{s.texte}</strong> : <span key={j}>{s.texte}</span>))}
          </span>
        )
      })}
    </span>
  )
}
