/**
 * TYPES PROPOSÉS À LA MISE EN LOT — miroir terrain de ProductionType::active()
 * (type ET espèce actifs), regroupés par espèce comme au bureau. Fonction
 * PURE, éprouvée hors navigateur (mobile/tests/miseEnLot.test.mjs).
 *
 * Le bureau ne lance que les espèces activées ; le téléphone proposait tous
 * les types, à plat et sans leur espèce : « Engraissement » de quoi ?
 */
import type { RefProductionType } from '../api/types'

export interface GroupeDEspece {
  espece: string
  types: RefProductionType[]
}

/** Un serveur antérieur n'envoie pas `utilisable` : on ne masque rien. */
export function typeLancable(type: Pick<RefProductionType, 'utilisable'>): boolean {
  return type.utilisable !== false
}

export function typesParEspece(types: RefProductionType[], sansEspece = 'Autres'): GroupeDEspece[] {
  const groupes = new Map<string, RefProductionType[]>()
  for (const type of types.filter(typeLancable)) {
    const espece = type.species_name?.trim() || sansEspece
    groupes.set(espece, [...(groupes.get(espece) ?? []), type])
  }
  return [...groupes.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([espece, liste]) => ({ espece, types: liste.sort((a, b) => a.name_fr.localeCompare(b.name_fr)) }))
}
