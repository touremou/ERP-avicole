/**
 * Anti-corvée de saisie — préremplissage « comme la dernière fois ».
 *
 * Les saisies quotidiennes du terrain se ressemblent d'un jour à l'autre
 * (même aliment, même produit de nettoyage, même équipement) : plutôt que
 * de tout retaper, l'écran se prérempli depuis la DERNIÈRE saisie locale
 * du même type (my_records, qui vit déjà sur l'appareil — zéro réseau).
 * L'agent ne corrige que ce qui change. Même doctrine que le module
 * Eau & Énergie web (« comme hier ») et la dérivation carburant/coût.
 */
import { db, type MyRecord } from './db'

/**
 * Dernier payload local d'un type d'opération, optionnellement filtré
 * (ex. même lot, même zone). Renvoie null si aucune saisie ne correspond.
 */
export async function lastPayloadOf(
  type: MyRecord['type'],
  matches?: (payload: Record<string, unknown>) => boolean,
): Promise<Record<string, unknown> | null> {
  const records = (await db.my_records.where('type').equals(type).toArray())
    .sort((a, b) => b.created_at.localeCompare(a.created_at)) // plus récent d'abord

  for (const record of records) {
    if (!matches || matches(record.payload)) return record.payload
  }

  return null
}
