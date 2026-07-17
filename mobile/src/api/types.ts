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
  /** Langue du profil web (users.locale) — adoptée par la PWA au login. */
  locale?: string | null
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
    /** Employé rattaché : sert à ne montrer que les lots qui me sont affectés. */
    employee_id?: number | null
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
  | 'health_incident.create'
  // Phase 3 — cultures, abattoir, provenderie.
  | 'harvest.create'
  | 'crop_input.create'
  | 'slaughter.execute'
  | 'mill_production.complete'
  // Phase 3 — cœur sanitaire HACCP abattoir.
  | 'slaughter_reception.create'
  | 'ccp_record.create'
  | 'temperature_log.create'
  | 'cleaning_log.create'
  | 'byproduct.create'
  // Tâches assignées : cocher « faite » depuis le terrain.
  | 'task.complete'
  // Tâche personnelle créée depuis le terrain (auto-assignée).
  | 'task.create'

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
    production_types: PullEntity<RefProductionType>
    // Phase 3 (optionnels : un serveur antérieur ne les renvoie pas).
    plots?: PullEntity<RefPlot>
    crop_cycles?: PullEntity<RefCropCycle>
    slaughter_orders?: PullEntity<RefSlaughterOrder>
    providers?: PullEntity<RefProvider>
    formulas?: PullEntity<RefFormula>
    mill_productions?: PullEntity<RefMillProduction>
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
  production_type_id: number | null
  /** Responsable du lot (employees.id) — pour le scoping « mes lots ». */
  employee_id: number | null
  initial_quantity: number
  current_quantity: number
  qty_dead: number
  arrival_date: string
  updated_at: string
  /** Calculé serveur : le lot est en âge/phase de collecte d'œufs (règle de souche). */
  can_collect_eggs?: boolean
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
  alert_threshold: number | null
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

// ── Tâches assignées (miroir « Mes tâches ») ───────────────────────────

export interface RefTask {
  id: number
  title: string
  category: string
  priority: string | null
  status: string
  scheduled_date: string
  scheduled_time: string | null
  batch_id: number | null
  building_id: number | null
  plot_id: number | null
}

export interface TasksResponse {
  tasks: RefTask[]
  server_time: string
}

export interface RefProductionType {
  id: number
  slug: string
  name_fr: string
  updated_at: string
}

// ── Référentiels Phase 3 (cultures / abattoir / provenderie) ────────────

export interface RefPlot {
  id: number
  code: string
  name: string
  status: string
  area_ha: string | number | null
  updated_at: string
}

/** Statuts « en cours » : en_cours | recolte (miroir de CropCycle::IN_PROGRESS_STATUSES). */
export interface RefCropCycle {
  id: number
  uuid: string | null
  plot_id: number
  code: string
  crop_name: string
  variety: string | null
  status: string
  /** Responsable du cycle (employees.id) — scoping « mes cultures ». */
  employee_id: number | null
  planting_date: string | null
  updated_at: string
}

export interface RefSlaughterOrder {
  id: number
  order_number: string
  batch_id: number | null
  planned_date: string
  planned_quantity: number
  status: string
  /** Utilisateurs concernés (users.id) — scoping « mes abattages ». */
  requested_by: number | null
  executed_by: number | null
  updated_at: string
}

/** Éleveurs livreurs pour la réception du vif (CCP 1). */
export interface RefProvider {
  id: number
  name: string
  type: string | null
  status: string | null
  updated_at: string
}

export interface RefFormula {
  id: number
  name: string
  code: string | null
  target_type: string | null
  is_active: boolean
  updated_at: string
}

export interface RefMillProduction {
  id: number
  batch_number: string
  formula_id: number | null
  quantity_produced: string | number
  status: string
  /** Opérateur / superviseur (users.id) — scoping « mes OP ». */
  operator_id: number | null
  supervisor_id: number | null
  started_at: string | null
  updated_at: string
}

export interface PhotoUploadResponse {
  path: string
  url: string
  server_time: string
}
