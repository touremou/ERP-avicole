/**
 * Découpage de la file d'envoi — fonction PURE, sans Dexie ni réseau, pour être
 * éprouvée hors navigateur (`node --experimental-strip-types --test mobile/tests/`).
 */

/**
 * Découpe la file en lots d'un seul site, dans l'ordre de saisie au sein de
 * chaque site. Les saisies sans site (antérieures, ou ferme par défaut) forment
 * leur propre groupe et partent sous l'en-tête courant, comme avant.
 */
export function lotsParSite<T extends { farm_id?: number }>(entries: T[], taille: number): T[][] {
  const parSite = new Map<number | undefined, T[]>()
  for (const e of entries) {
    const groupe = parSite.get(e.farm_id) ?? []
    groupe.push(e)
    parSite.set(e.farm_id, groupe)
  }
  const lots: T[][] = []
  for (const groupe of parSite.values()) {
    for (let i = 0; i < groupe.length; i += taille) lots.push(groupe.slice(i, i + taille))
  }
  return lots
}
