/**
 * SUIVI PAR ESPÈCE — ce que les formulaires proposent selon l'animal du lot.
 * Fonctions PURES (mobile/tests/suivi.test.mjs).
 *
 * La déclaration vit au serveur (App\Support\SuiviParEspece) et descend dans
 * la session (`me.species_forms`, en cache hors ligne) : pas de seconde copie
 * des maladies ou des modes ici. Un serveur plus ancien ne l'envoie pas — on
 * retombe alors sur un suivi neutre, jamais sur celui de la volaille.
 */
export interface Suivi {
  symptomes: string[]
  produits: Record<string, string[]>
  modes: string[]
  masquer: string[]
}

export type Rubrique = 'litiere' | 'picage' | 'boiteux' | 'ambiance'

/** Suivi neutre : aucune proposition propre à une espèce. */
export const SUIVI_NEUTRE: Suivi = {
  symptomes: ['Prostration', 'Perte d’appétit', 'Diarrhée', 'Boiterie'],
  produits: {},
  modes: ['Eau de boisson', 'Injection', 'Aliment', 'Voie orale'],
  masquer: [],
}

/** Le suivi de la famille d'un lot : le sien, sinon celui par défaut du serveur, sinon le neutre. */
export function suiviPour(
  referentiel: Record<string, Suivi> | null | undefined,
  famille: string | null | undefined,
): Suivi {
  if (!referentiel) return SUIVI_NEUTRE

  return (famille ? referentiel[famille] : undefined) ?? referentiel.defaut ?? SUIVI_NEUTRE
}

/** Cette rubrique du pointage a-t-elle un sens pour cet animal ? */
export function rubriqueVisible(suivi: Suivi, rubrique: Rubrique): boolean {
  return !suivi.masquer.includes(rubrique)
}

/** Produits proposés pour un type d'intervention (Vaccin, Traitement, Vitamine). */
export function produitsProposes(suivi: Suivi, type: string): string[] {
  return suivi.produits[type] ?? []
}
