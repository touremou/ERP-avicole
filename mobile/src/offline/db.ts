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
  ApiNotification,
  MeResponse,
  OperationType,
  RefBatch,
  RefBuilding,
  RefClient,
  RefCropCycle,
  RefFormula,
  RefMillProduction,
  RefWaterSource,
  RefCropSpecies,
  RefPlot,
  RefProduct,
  RefProductionType,
  RefProvider,
  RefSlaughterOrder,
  RefStock,
  RefTask,
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

/** Photo en attente de téléversement (référencée par payload.photo_uuid). */
export interface LocalPhoto {
  uuid: string
  blob: Blob
  context: 'incident' | 'expense' | 'daily_check' | 'reception' | 'cleaning' | 'task'
  /** Chemin serveur une fois téléversée (le payload de l'op est alors mis à jour). */
  uploaded_path: string | null
  created_at: string
}

class ErpMobileDb extends Dexie {
  ref_batches!: Table<RefBatch, number>
  ref_buildings!: Table<RefBuilding, number>
  ref_stocks!: Table<RefStock, number>
  ref_clients!: Table<RefClient, number>
  ref_products!: Table<RefProduct, number>
  outbox!: Table<OutboxEntry, string>
  my_records!: Table<MyRecord, string>
  notifications!: Table<ApiNotification, string>
  photos!: Table<LocalPhoto, string>
  ref_production_types!: Table<RefProductionType, number>
  ref_plots!: Table<RefPlot, number>
  ref_crop_cycles!: Table<RefCropCycle, number>
  ref_slaughter_orders!: Table<RefSlaughterOrder, number>
  ref_providers!: Table<RefProvider, number>
  ref_formulas!: Table<RefFormula, number>
  ref_mill_productions!: Table<RefMillProduction, number>
  ref_water_sources!: Table<RefWaterSource, number>
  ref_crop_species!: Table<RefCropSpecies, number>
  tasks!: Table<RefTask, number>
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
      notifications: 'id, read_at, created_at',
      meta: 'key',
    })
    // v2 (Phase 1) : photos hors-ligne + référentiel des types de production.
    this.version(2).stores({
      photos: 'uuid, created_at, uploaded_path',
      ref_production_types: 'id, slug',
      // + index `code` pour la résolution du scan QR.
      ref_batches: 'id, uuid, code, building_id, status',
    })
    // v3 (Phase 3) : référentiels cultures, abattoir, provenderie.
    this.version(3).stores({
      ref_plots: 'id, status',
      ref_crop_cycles: 'id, plot_id, status',
      ref_slaughter_orders: 'id, batch_id, status',
      ref_formulas: 'id',
      ref_mill_productions: 'id, formula_id, status',
    })
    // v4 (Phase 3 — HACCP abattoir) : éleveurs livreurs pour la réception du vif.
    this.version(4).stores({
      ref_providers: 'id, name',
    })
    // v5 : tâches assignées (miroir « Mes tâches », remplacement complet à la sync).
    this.version(5).stores({
      tasks: 'id, scheduled_date, status, batch_id',
    })
    // v6 : citernes / sources d'eau (ravitaillement terrain hors-ligne).
    this.version(6).stores({
      ref_water_sources: 'id, type',
    })
    // v7 : catalogue des cultures (espèces) — liste au pointage de semis.
    this.version(7).stores({
      ref_crop_species: 'id, name',
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
