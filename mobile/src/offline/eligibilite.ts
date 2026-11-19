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

/**
 * Sous-produits de l'ESPÈCE de l'ordre (ButcheryNomenclature::byproductTypesFor,
 * servis avec l'ordre) : pas de plumes pour un ovin. Sans ordre, ou référentiel
 * ancien : tout reste proposé, le serveur tranchera.
 */
export function sousProduitAdmis(
  type: string,
  ordre: { byproduct_types?: string[] } | null | undefined,
): boolean {
  return !ordre?.byproduct_types || ordre.byproduct_types.includes(type)
}

/** Racines des mots d'au moins 3 lettres (4 premières lettres, sans accents) — CropRecipe::racines. */
export function racines(texte: string): string[] {
  const plat = texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
  const mots = plat.split(/[^a-z0-9]+/).filter((mot) => mot.length >= 3)
  return [...new Set(mots.map((mot) => mot.slice(0, 4)))]
}

/**
 * Cette recette transforme-t-elle cette culture ? (CropRecipe::convientA) —
 * « Gari » n'est pas proposé pour une récolte de maïs. Sans intrant déclaré,
 * ou serveur antérieur, la recette reste proposée.
 */
export function recetteConvient(recette: { intrants?: string[] }, culture: string | null | undefined): boolean {
  if (!recette.intrants || recette.intrants.length === 0 || !culture) return true
  const cibles = racines(culture)
  return recette.intrants.some((intrant) => racines(intrant).some((r) => cibles.includes(r)))
}

/** Herbivores (RawFeeding::FAMILLES) — repli quand le serveur n'a pas encore servi le drapeau. */
export const FAMILLES_ALIMENTATION_BRUTE = ['petit_ruminant', 'grand_ruminant', 'lagomorphe']
/** Ruminants (GrazingSession::FAMILLES). */
export const FAMILLES_PATURAGE = ['petit_ruminant', 'grand_ruminant']

type LotNourri = { accepte_alimentation_brute?: boolean; accepte_paturage?: boolean; species_family?: string | null }

/** Batch::accepteAlimentationBrute — « Nourrir (brut) » : herbivores seulement. */
export function nourritAuBrut(lot: LotNourri): boolean {
  return lot.accepte_alimentation_brute ?? FAMILLES_ALIMENTATION_BRUTE.includes(String(lot.species_family ?? ''))
}

/** Batch::acceptePaturage — sortie au pâturage : ruminants seulement. */
export function sortAuPaturage(lot: LotNourri): boolean {
  return lot.accepte_paturage ?? FAMILLES_PATURAGE.includes(String(lot.species_family ?? ''))
}
