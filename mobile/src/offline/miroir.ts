/**
 * À QUI APPARTIENT LE MIROIR LOCAL ? — fonction PURE, éprouvée hors navigateur
 * (mobile/tests/miroir.test.mjs).
 *
 * Le miroir de référence (ref_* : personnel, clients et leurs soldes, ventes,
 * lots…) est rempli par des pulls INCRÉMENTAUX (`since = last_pull_at`). Un
 * delta ajoute et retire ce qui a changé ; il n'efface jamais ce que le compte
 * courant n'a simplement PAS le droit de voir.
 *
 * Sur un téléphone de service qui passe de main en main, le compte suivant
 * héritait donc du miroir du précédent : l'annuaire du personnel montré à un
 * ouvrier sans droit RH, les clients d'un site montrés à l'équipe d'un autre —
 * et, sur un autre site, deux fermes MÉLANGÉES dans les mêmes listes, puisque
 * le delta du nouveau site s'ajoutait à l'ancien.
 *
 * La règle : le miroir appartient à un (compte, site). S'il change, on repart
 * d'un bootstrap complet, qui vide les tables avant de les remplir. Un appareil
 * qui ne sait pas à qui appartient son miroir (versions antérieures) le
 * reconstruit une fois : on ne montre pas ce dont on ignore la provenance.
 */
export interface ProprietaireMiroir {
  user_id: number
  farm_id: number | null
}

export function miroirAReconstruire(
  proprietaire: ProprietaireMiroir | undefined | null,
  compte: ProprietaireMiroir,
): boolean {
  if (!proprietaire) return true
  return proprietaire.user_id !== compte.user_id || (proprietaire.farm_id ?? null) !== (compte.farm_id ?? null)
}
