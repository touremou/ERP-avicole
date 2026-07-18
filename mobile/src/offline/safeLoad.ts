/**
 * safeLoad — enveloppe les chargements locaux (Dexie) pour qu'une erreur ne
 * passe JAMAIS inaperçue. Contexte du bug #90 : un db.ref_stocks.orderBy() sur
 * un champ non indexé jetait, le `void load()` avalait le rejet, et l'écran
 * restait vide sans le moindre signal. Ici on journalise le contexte + l'erreur
 * (visible dans la console distante / le débogage terrain) tout en gardant l'UI
 * fonctionnelle (l'état conserve sa dernière valeur connue).
 */
export function safeLoad(context: string, run: () => Promise<void>): Promise<void> {
  return run().catch((error) => {
    console.error(`[chargement:${context}]`, error)
  })
}
