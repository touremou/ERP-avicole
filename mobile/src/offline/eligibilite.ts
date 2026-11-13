/**
 * ÉLIGIBILITÉ — miroirs terrain des règles serveur qui disent QUI peut subir
 * QUELLE opération. Fonctions PURES, éprouvées hors navigateur
 * (mobile/tests/eligibilite.test.mjs).
 *
 * Audit de l'exploitation : plusieurs listes du téléphone proposaient des
 * entités que le serveur refuse ensuite (ou accepte à tort) — un poulailler
 * pour des moutons, « Ponte 1 » au pointage d'un lot de chair, une machine en
 * panne au lancement d'un OP, une source d'eau désactivée au relevé…
 * Chaque règle ici renvoie à sa source serveur. Un référentiel ancien, sans
 * l'attribut, ne masque rien : le serveur tranchera.
 */

/** Species::buildingIsCompatible, via ProductionType::buildingTypes. */
export function batimentAccueille(
  batiment: { type?: string | null },
  type: { building_types?: string[] } | null | undefined,
): boolean {
  if (!type?.building_types) return true
  return type.building_types.includes(String(batiment.type ?? ''))
}

/**
 * Batch::accepteAliment : une phase STANDARD d'un autre secteur est refusée ;
 * un article hors nomenclature (aliment maison) passe.
 */
export function alimentAccepte(
  article: string,
  lot: { feed_phases?: string[] } | null | undefined,
  phasesStandard: string[] | undefined,
): boolean {
  if (!lot?.feed_phases || !phasesStandard) return true
  return !phasesStandard.includes(article) || lot.feed_phases.includes(article)
}

/** Provider::scopeActive — le bureau ne propose que les fournisseurs actifs. */
export function fournisseurActif(fournisseur: { status?: string | null }): boolean {
  return fournisseur.status == null || fournisseur.status === 'Actif'
}

/** La liste du bureau (MillProductionController::create) : « Opérationnel » seulement. */
export function machineOperationnelle(machine: { status?: string | null }): boolean {
  return machine.status === 'Opérationnel'
}

/**
 * Surface DISPONIBLE d'une parcelle = surface totale − surfaces des cycles en
 * cours (garde optimiste, revérifiée au serveur) — CropCycleController::create.
 */
export function surfaceLibre(
  parcelle: { id: number; area_ha?: string | number | null },
  cycles: { plot_id?: number | null; status?: string | null; area_used_ha?: string | number | null }[],
): number {
  const utilisee = cycles
    .filter((c) => c.plot_id === parcelle.id && (c.status === 'en_cours' || c.status === 'recolte'))
    .reduce((somme, c) => somme + Number(c.area_used_ha ?? 0), 0)
  return Math.max(0, Number(parcelle.area_ha ?? 0) - utilisee)
}

/** CropCycle::isArchived — un cycle terminé ou abandonné ne reçoit plus de saisie. */
export function cycleArchive(statut: string | null | undefined): boolean {
  return statut === 'termine' || statut === 'abandonne'
}
