/**
 * LOTS ET BÂTIMENTS VIRTUELS — miroir terrain de Batch::scopeLive /
 * Batch::isVirtual et de Building::scopePhysical. Fonctions PURES, éprouvées
 * hors navigateur (mobile/tests/virtuels.test.mjs).
 *
 * Un couvain d'œufs achetés à l'extérieur est tracé par un lot VIRTUEL
 * (effectif initial nul) parqué dans le bâtiment virtuel « Zone Fournisseurs
 * Externes » (StartIncubation). Le bureau les écarte de toute liste ; le
 * terrain les recevait et les proposait au soin, à la traite, au nourrissage,
 * à la dépense, à la réception d'aliment, et le bâtiment virtuel à la mise en
 * lot — des saisies sur des animaux qui n'existent pas.
 */
export const ZONE_VIRTUELLE = 'Zone Fournisseurs Externes'
export const STATUT_ANNULE = 'Annulé'

/** Lot d'animaux réels : effectif initial non nul et lot non annulé. */
export function estLotReel(lot: { initial_quantity?: number | null; status?: string | null }): boolean {
  return Number(lot.initial_quantity ?? 0) > 0 && lot.status !== STATUT_ANNULE
}

/** Bâtiment physique : tout sauf la zone virtuelle de traçabilité. */
export function estBatimentReel(batiment: { name?: string | null }): boolean {
  return batiment.name !== ZONE_VIRTUELLE
}
