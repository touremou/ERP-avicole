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

/**
 * Un ÉVÉNEMENT D'ÉLEVAGE (pointage, collecte, incident, soin) ne se consigne
 * que sur une bande ACTIVE. La fiche du bureau ne propose ces actions qu'à un
 * lot actif — un lot soldé n'y offre que la clôture définitive. La fiche
 * terrain (cible du QR) les proposait sur tout lot : le pointage d'un lot
 * soldé ou clôturé partait en file et revenait refusé à la synchronisation.
 */
export function accepteEvenementDElevage(statut: string | null | undefined): boolean {
  return statut === STATUT_ACTIF
}

/** Pourquoi la fiche terrain ne propose plus d'action d'élevage (clé i18n). */
export function motifSansEvenement(statut: string | null | undefined): string | null {
  if (accepteEvenementDElevage(statut)) return null
  return statut === STATUT_SOLDE
    ? 'Lot soldé (effectif à zéro) : plus d’événement d’élevage. La clôture définitive se fait au bureau.'
    : 'Lot clôturé : consultation seule.'
}

/**
 * BÂTIMENT EN VIDE SANITAIRE — miroir de Building::refusDeMiseEnPlace.
 * Décision de l'exploitation : pas de nouvelle bande tant que le délai n'est
 * pas écoulé (ou le protocole d'hygiène validé, ce que dit le statut). Les
 * jours restants viennent du serveur ; un référentiel ancien sans eux ne bloque
 * pas — le serveur tranchera.
 */
export function joursDeVideSanitaire(batiment: { status?: string; sanitary_break_remaining_days?: number } | null | undefined): number {
  if (!batiment || batiment.status !== 'En désinfection') return 0
  return Math.max(0, Number(batiment.sanitary_break_remaining_days ?? 0))
}
