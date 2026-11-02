/**
 * STATUTS D'UNE BANDE — le miroir terrain de Batch::accepteImputation.
 * Fonction PURE, éprouvée hors navigateur (mobile/tests/statutsDeLot.test.mjs).
 *
 * Décision de l'exploitation (fermeture en deux temps) : une bande dont
 * l'effectif tombe à zéro passe « Soldé ». Elle ne reçoit plus d'événement
 * d'élevage, mais ses DERNIERS COÛTS s'y imputent encore — dépense, aliment
 * livré — jusqu'à la clôture définitive.
 *
 * Le bureau le permettait (ExpenseController : Batch::imputable(), achat
 * d'aliment depuis la fiche du lot) et le serveur l'acceptait ; les écrans
 * terrain ne proposaient que les bandes « Actif ». La même dépense passait au
 * bureau et ne se saisissait pas au champ.
 */
export const STATUT_ACTIF = 'Actif'
export const STATUT_SOLDE = 'Soldé'

/** Les statuts auxquels un coût s'impute (Batch::accepteImputation). */
export const STATUTS_IMPUTABLES: string[] = [STATUT_ACTIF, STATUT_SOLDE]

export function accepteImputation(statut: string | null | undefined): boolean {
  return STATUTS_IMPUTABLES.includes(String(statut ?? ''))
}
