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
  RefEmployee,
  RefEnergySource,
  RefIncubation,
  RefMillMachine,
  RefSale,
  RefSaleItem,
  RefSalePriceList,
  RefSalePriceListItem,
  RefEmployeeLeave,
  RefCropRecipe,
  RefPendingHarvest,
  RefStoredLot,
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
  ref_sales!: Table<RefSale, number>
  ref_sale_items!: Table<RefSaleItem, number>
  ref_mill_machines!: Table<RefMillMachine, number>
  ref_employees!: Table<RefEmployee, number>
  ref_incubations!: Table<RefIncubation, number>
  ref_energy_sources!: Table<RefEnergySource, number>
  ref_sale_price_lists!: Table<RefSalePriceList, number>
  ref_sale_price_list_items!: Table<RefSalePriceListItem, number>
  ref_employee_leaves!: Table<RefEmployeeLeave, number>
  ref_crop_recipes!: Table<RefCropRecipe, number>
  ref_pending_harvests!: Table<RefPendingHarvest, number>
  ref_stored_lots!: Table<RefStoredLot, number>
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
    // v8 (M2) : créances ouvertes + leurs lignes — encaissement et retour
    // client saisis chez le client, hors réseau.
    this.version(8).stores({
      ref_sales: 'id, client_id, payment_status',
      ref_sale_items: 'id, sale_id',
    })
    // v9 (M4) : machines du moulin + employés actifs (lancement d'OP terrain).
    this.version(9).stores({
      ref_mill_machines: 'id, status',
      ref_employees: 'id, status',
    })
    // v10 (M5) : couvoir (cycles ouverts) + sources d'énergie.
    this.version(10).stores({
      ref_incubations: 'id, status',
      ref_energy_sources: 'id, type',
    })
    // v11 (M6) : tarifs par palier client (POS hors réseau) + congés validés
    // courants (le pointage ne déclare pas présent un absent justifié).
    this.version(11).stores({
      ref_sale_price_lists: 'id, is_default',
      ref_sale_price_list_items: 'id, sale_price_list_id, product_id',
      ref_employee_leaves: 'id, employee_id',
    })
    // v12 (T1) : atelier de transformation végétale — recettes (rendement de
    // référence) et récoltes en attente d'être séchées.
    this.version(12).stores({
      ref_crop_recipes: 'id, transformation_type',
      ref_pending_harvests: 'id, crop_cycle_id',
    })
    // v13 (T2) : lots en conservation — le contrôle périodique se fait au
    // magasin, balance en main, souvent sans réseau.
    this.version(13).stores({
      ref_stored_lots: 'id, status',
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
