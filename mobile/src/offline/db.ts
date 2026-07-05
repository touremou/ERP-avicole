/**
 * Base locale Dexie — le cœur de l'offline-first (cf. phase-0-spec.md §6).
 *
 * ├─ ref_*       miroir des données de référence (clé = id serveur)
 * ├─ outbox      file d'opérations à pousser { op_uuid, type, payload, status }
 * ├─ my_records  saisies locales pour affichage immédiat (optimistic UI)
 * └─ meta        { key, value } : token, me, farm_id, last_pull_at
 *
 * Statuts d'outbox :
 *   pending → à pousser ; review → refus définitif (bac « À corriger ») ;
 *   les succès sont RETIRÉS de la file (l'historique vit dans my_records).
 */
import Dexie, { type Table } from 'dexie'
import type {
  MeResponse,
  OperationType,
  RefBatch,
  RefBuilding,
  RefClient,
  RefProduct,
  RefStock,
} from '../api/types'

export interface OutboxEntry {
  op_uuid: string
  type: OperationType
  payload: Record<string, unknown>
  status: 'pending' | 'review'
  attempts: number
  created_at: string
  last_error: string | null
  /** Erreurs de validation renvoyées par le serveur (bac « À corriger »). */
  server_errors: Record<string, string[]> | null
}

export interface MyRecord {
  uuid: string
  type: OperationType
  /** Libellé humain affiché dans « Mon activité » (ex. « Pointage P-001 »). */
  label: string
  payload: Record<string, unknown>
  sync_status: 'pending' | 'synced' | 'review'
  created_at: string
}

export interface MetaEntry {
  key: string
  value: unknown
}

class ErpMobileDb extends Dexie {
  ref_batches!: Table<RefBatch, number>
  ref_buildings!: Table<RefBuilding, number>
  ref_stocks!: Table<RefStock, number>
  ref_clients!: Table<RefClient, number>
  ref_products!: Table<RefProduct, number>
  outbox!: Table<OutboxEntry, string>
  my_records!: Table<MyRecord, string>
  meta!: Table<MetaEntry, string>

  constructor() {
    super('erp-mobile')
    this.version(1).stores({
      ref_batches: 'id, uuid, building_id, status',
      ref_buildings: 'id, type',
      ref_stocks: 'id, category',
      ref_clients: 'id, name',
      ref_products: 'id, product_type',
      outbox: 'op_uuid, status, created_at',
      my_records: 'uuid, type, sync_status, created_at',
      meta: 'key',
    })
  }
}

export const db = new ErpMobileDb()

// ── Helpers meta typés ───────────────────────────────────────────────────

export async function getMeta<T>(key: string): Promise<T | undefined> {
  return (await db.meta.get(key))?.value as T | undefined
}

export async function setMeta(key: string, value: unknown): Promise<void> {
  await db.meta.put({ key, value })
}

export const session = {
  token: () => getMeta<string>('token'),
  me: () => getMeta<MeResponse>('me'),
  lastPullAt: () => getMeta<string>('last_pull_at'),
}
