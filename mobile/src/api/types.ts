/**
 * Contrats de l'API v1 (miroir strict de routes/api.php + SyncController).
 * Toute évolution côté Laravel doit se refléter ici — c'est LE point de
 * couplage entre le terrain et le serveur.
 */

export interface LoginResponse {
  token: string
  user: ApiUser
  server_time: string
}

export interface ApiUser {
  id: number
  name: string
  email: string
  role: string | null
}

/** GET /auth/me — payload mis en cache pour la home par rôle + gate offline. */
export interface MeResponse {
  user: ApiUser
  role: { slug: string | null; label: string | null }
  /** { elevage: ["L","C","M"], commerce: ["L"] } — déjà filtré par la licence. */
  permissions: Record<string, PermissionLevel[]>
  scope: {
    farm_id: number | null
    farms: { id: number; name: string; is_default: boolean }[]
  }
  server_time: string
}

export type PermissionLevel = 'L' | 'C' | 'M' | 'S'

// ── Synchronisation ──────────────────────────────────────────────────────

export type OperationType =
  | 'daily_check.create'
  | 'egg_collection.create'
  | 'stock_movement.create'
  | 'sale.create'
  | 'expense.create'
  | 'batch.upsert'

export interface PushOperation {
  op_uuid: string
  type: OperationType
  payload: Record<string, unknown>
}

export type PushStatus =
  | 'success'
  | 'already_synced'
  | 'conflict'
  | 'permission_denied'
  | 'validation_failed'
  | 'error'

export interface PushResult {
  op_uuid: string
  status: PushStatus
  message?: string
  errors?: Record<string, string[]>
  server_id?: number
}

export interface PushResponse {
  server_time: string
  results: PushResult[]
}

export interface PullEntity<T = Record<string, unknown>> {
  upserts: T[]
  deletes: number[]
}

export interface PullResponse {
  server_time: string
  entities: {
    batches: PullEntity<RefBatch>
    buildings: PullEntity<RefBuilding>
    stocks: PullEntity<RefStock>
    clients: PullEntity<RefClient>
    products: PullEntity<RefProduct>
  }
}

// ── Données de référence (colonnes de la liste blanche du pull) ─────────

export interface RefBatch {
  id: number
  uuid: string | null
  code: string
  status: string
  building_id: number
  species_id: number | null
  initial_quantity: number
  current_quantity: number
  qty_dead: number
  arrival_date: string
  updated_at: string
}

export interface RefBuilding {
  id: number
  name: string
  type: string
  capacity: number
  status: string
  updated_at: string
}

export interface RefStock {
  id: number
  item_name: string
  category: string
  unit: string
  current_quantity: number
  updated_at: string
}

export interface RefClient {
  id: number
  client_id: string | null
  name: string
  category: string | null
  phone: string | null
  balance: number
  status: string | null
  updated_at: string
}

export interface RefProduct {
  id: number
  name: string
  sku: string | null
  product_type: string
  unit: string | null
  base_price: number
  is_active: boolean
  updated_at: string
}

export interface DeviceInfo {
  id: number
  name: string
  last_used_at: string | null
  created_at: string | null
  current: boolean
}

// ── Notifications (centre mobile — miroir de la cloche web) ─────────────

export interface ApiNotification {
  id: string
  type: string
  title: string
  message: string
  severity: string
  url: string | null
  read_at: string | null
  created_at: string
}

export interface NotificationsResponse {
  notifications: ApiNotification[]
  unread_count: number
  server_time: string
}
