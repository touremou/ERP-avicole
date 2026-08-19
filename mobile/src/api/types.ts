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
  /** Téléphone WhatsApp (users.whatsapp_phone) — préremplit l'éditeur de profil. */
  phone?: string | null
  /** URL de la photo de profil (null → le client affiche les initiales). */
  avatar_url?: string | null
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
  /**
   * Règles de la ferme dont les écrans terrain ont besoin.
   *
   * La PWA n'en recevait AUCUNE : elle ne pouvait donc pas honorer une règle
   * définie par la ferme. La coupure de caisse en est le cas concret — le
   * serveur arrondit le total à l'enregistrement, l'écran annonçait le brut.
   *
   * Optionnel : un serveur plus ancien ne renvoie pas ce bloc.
   */
  settings?: {
    currency?: string
    /** Coupure de caisse (0 = pas d'arrondi). Cf. cash_round() côté serveur. */
    cash_rounding?: number
    /**
     * Catégories de tâche, SERVIES par le serveur (TaskTemplate::CATEGORIES).
     *
     * L'écran mobile en portait six en dur quand le bureau en proposait
     * quatorze : arroser se classait « alimentation », faute de mieux. Les
     * servir évite qu'une liste métier vive à deux endroits — ajouter une
     * catégorie au bureau suffit désormais.
     */
    task_categories?: { key: string; label: string; emoji: string; group: string }[]
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
  | 'crop_cycle.create'
  | 'harvest.create'
  | 'crop_input.create'
  | 'task.start'
  | 'task.release'
  | 'slaughter.execute'
  | 'slaughter.close'
  | 'slaughter.cutting'
  | 'health_check.create'
  | 'payment.create'
  | 'sale_return.create'
  | 'inventory_count.create'
  | 'feed_purchase.create'
  | 'mill_production.create'
  | 'incubation.mirage'
  | 'incubation.hatch'
  | 'milk_production.create'
  | 'energy_reading.create'
  | 'water_reading.create'
  | 'attendance.create'
  | 'crop_transformation.create'
  | 'stored_lot.check'
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
  | 'task.dispatch'
  // Ravitaillement d'une citerne d'eau (appoint) depuis le terrain.
  | 'water_refill.create'

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
    water_sources?: PullEntity<RefWaterSource>
    crop_species?: PullEntity<RefCropSpecies>
    // M2 — créances ouvertes (encaissement / retour chez le client).
    sales?: PullEntity<RefSale>
    sale_items?: PullEntity<RefSaleItem>
    // M4 — machines du moulin et employés (superviseur d'OP).
    mill_machines?: PullEntity<RefMillMachine>
    employees?: PullEntity<RefEmployee>
    // M5 — couvoir et sources d'énergie.
    incubations?: PullEntity<RefIncubation>
    energy_sources?: PullEntity<RefEnergySource>
    // M6 — tarifs par palier client (POS hors réseau) et congés validés.
    sale_price_lists?: PullEntity<RefSalePriceList>
    sale_price_list_items?: PullEntity<RefSalePriceListItem>
    employee_leaves?: PullEntity<RefEmployeeLeave>
    // T1 — atelier de transformation végétale (le séchoir est dehors).
    crop_recipes?: PullEntity<RefCropRecipe>
    pending_harvests?: PullEntity<RefPendingHarvest>
    // T2 — lots en conservation à contrôler au magasin.
    stored_lots?: PullEntity<RefStoredLot>
  }
}

/** Cycle d'incubation ouvert (M5) — mirage puis éclosion en salle. */
export interface RefIncubation {
  id: number
  uuid: string | null
  code_incubation: string
  batch_id: number | null
  incubator_id: number | null
  start_date: string
  hatch_date_expected: string | null
  eggs_count: number
  fertile_eggs: number | null
  hatched_chicks: number | null
  status: string
  updated_at: string
}

/** Source d'énergie (M5) — relevé du compteur groupe sur place. */
export interface RefEnergySource {
  id: number
  name: string
  type: string | null
  fuel_type: string | null
  current_fuel_level: number | null
  status: string | null
  updated_at: string
}

/** Machine du moulin (M4) — choix des machines au lancement d'un OP. */
export interface RefMillMachine {
  id: number
  name: string
  type: string | null
  capacity_per_hour: number | null
  status: string | null
  updated_at: string
}

/** Employé actif (M4) — superviseur d'un OP. Colonnes nominatives seulement. */
export interface RefEmployee {
  id: number
  employee_id: string | null
  first_name: string | null
  last_name: string | null
  job_title: string | null
  /**
   * Service de rattachement (Elevage, Cultures, Commerce…).
   *
   * Sert à l'écran d'affectation : le serveur refuse une tâche confiée hors du
   * service concerné, et l'écran doit pouvoir le dire AVANT l'envoi plutôt que
   * de laisser le refus arriver à la synchronisation.
   */
  department: string | null
  status: string | null
  updated_at: string
}

/** Créance ouverte : vente non soldée descendue au terrain (M2). */
export interface RefSale {
  id: number
  uuid: string | null
  reference: string
  client_id: number | null
  sale_date: string
  status: string
  total_amount: number
  paid_amount: number
  payment_status: string
  updated_at: string
}

/** Ligne d'une créance ouverte — base du retour client (M2). */
export interface RefSaleItem {
  id: number
  sale_id: number
  product_name: string
  product_type: string | null
  quantity: number
  unit: string | null
  unit_price: number
  total: number
  updated_at: string
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
  /** Naissance/éclosion : l'âge s'en déduit. Absente sur les lots antérieurs. */
  birth_date?: string | null
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
  /** Tarif rattaché au client (M6) — pilote la cascade de prix du POS. */
  price_list_id?: number | null
  phone: string | null
  balance: number
  /**
   * Plafond de crédit. 0 = pas de plafond (convention du champ côté serveur).
   * Optionnel : un serveur antérieur à #244 ne le renvoie pas.
   */
  credit_limit?: number | null
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
  stock_id?: number | null
  is_favorite?: boolean
  /** Stock vendable (M6) — null = article non suivi, vendable librement. */
  available_quantity?: number | null
  is_active: boolean
  updated_at: string
}

/** Groupe de prix (tarif) rattachable à un client — M6. */
export interface RefSalePriceList {
  id: number
  name: string
  is_default: boolean
  updated_at: string
}

/**
 * Ligne de tarif : soit par ARTICLE (product_id défini), soit par CATÉGORIE
 * (product_id null + product_type). La cascade est appliquée côté terrain.
 */
export interface RefSalePriceListItem {
  id: number
  sale_price_list_id: number
  product_id: number | null
  product_type: string | null
  unit_price: number
  updated_at: string
}

/**
 * Lot en conservation (T2) — gardé pour être vendu plus cher plus tard.
 * L'objectif de prix et l'échéance descendent aussi : le contrôleur doit savoir
 * ce qu'il cherche à décider en pesant.
 */
export interface RefStoredLot {
  id: number
  uuid: string | null
  stock_id: number
  label: string
  quantity_initial: number
  quantity_current: number
  unit: string
  unit_cost: number | null
  target_unit_price: number | null
  last_market_price: number | null
  opened_at: string
  hold_until: string | null
  check_interval_days: number
  status: string
  updated_at: string
}

/** Recette de transformation végétale — rendement de référence (T1). */
export interface RefCropRecipe {
  id: number
  code: string | null
  name: string
  transformation_type: string
  output_product: string | null
  output_unit: string | null
  expected_yield_percent: number | null
  shelf_life_days: number | null
  is_active: boolean
  updated_at: string
}

/**
 * Récolte marquée « à transformer » et pas encore engagée (T1) : la liste de
 * travail de l'atelier. La choisir porte la traçabilité au lot et le coût
 * matière — le terrain n'a rien à ressaisir.
 */
export interface RefPendingHarvest {
  id: number
  uuid: string | null
  crop_cycle_id: number
  harvest_date: string
  quantity: number
  unit: string | null
  net_weight_kg: number | null
  quality: string | null
  destination: string
  stock_item_name: string | null
  updated_at: string
}

/** Congé validé courant — le pointage ne déclare pas présent un absent justifié. */
export interface RefEmployeeLeave {
  id: number
  employee_id: number
  start_date: string
  end_date: string
  status: string
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
  /** Consigne de l'étape d'itinéraire (S1) : stade, produit, dose, méthode. */
  description?: string | null
  scheduled_date: string
  scheduled_time: string | null
  batch_id: number | null
  building_id: number | null
  plot_id: number | null
  /** Cycle de culture et étape d'itinéraire dont la tâche est issue (S1). */
  crop_cycle_id?: number | null
  crop_protocol_item_id?: number | null
  // Preuve d'exécution exigée à la complétion (miroir serveur).
  proof_type?: 'aucune' | 'photo' | 'valeur'
  proof_label?: string | null
  proof_unit?: string | null
  // Verrou anti-doublon (miroir serveur) : prise active par un autre (grisée),
  // ou par moi (bouton « Terminer »).
  locked?: boolean
  claimed_by_me?: boolean
  claimant_name?: string | null
  // Libre-service : tâche du pool encore à prendre (badge « Libre »).
  is_pool?: boolean
}

/** Un indicateur de la fiche hebdomadaire (S2) — miroir de TechnicianWeekService. */
export interface WeekIndicator {
  key: string
  label: string
  /** null = NON MESURABLE (donnée absente) — volontairement distinct de 0. */
  value: number | null
  unit: string
  target: string
  tone: 'ok' | 'warn' | 'bad' | 'neutral'
  detail: string
}

export interface MyWeekResponse {
  has_sheet: boolean
  employee?: { id: number; name: string; job_title: string | null }
  week?: { iso: number; year: number; from: string; to: string }
  indicators?: WeekIndicator[]
  tasks?: {
    total: number; done: number; on_time: number; late: number; open: number
    crop_total: number; crop_done: number
  }
  batches?: Array<{
    id: number; code: string; building: string | null; age_days: number; current: number
    mortality_rate: number; fcr: number | null
    feed_gap_percent: number | null; feed_expected_kg: number | null; feed_actual_kg: number | null
  }>
  cycles?: Array<{
    id: number; code: string; crop_name: string; plot: string | null
    days_after_planting: number | null
    steps_total: number; steps_done: number; steps_late: number
  }>
  incidents?: number
  server_time: string
}

export interface TaskSummary {
  today: number
  overdue: number
  upcoming: number
  high_priority: number
  done_today: number
}

export interface TasksResponse {
  tasks: RefTask[]
  /** Récap « ma journée » — optionnel : un serveur antérieur ne le renvoie pas. */
  summary?: TaskSummary
  server_time: string
}

export interface SaleJournalEntry {
  id: number
  reference: string
  client_name: string | null
  type: string
  status: string
  total_amount: number
  paid_amount: number
  remaining: number
  payment_status: string
  created_at: string | null
}

export interface SeriesPoint {
  date: string
  value: number
}

export interface SalesJournalResponse {
  sales: SaleJournalEntry[]
  summary: { count: number; total: number; paid: number; remaining: number }
  series?: SeriesPoint[]
  server_time: string
}

export interface HarvestEntry {
  id: number
  crop: string | null
  variety: string | null
  cycle_code: string | null
  quantity: number
  unit: string
  weight_kg: number
  quality: string | null
}

export interface HarvestJournalResponse {
  harvests: HarvestEntry[]
  summary: { count: number; total_weight_kg: number }
  series?: SeriesPoint[]
  server_time: string
}

export interface SlaughterOrderEntry {
  id: number
  order_number: string
  batch: string | null
  client: string | null
  planned_quantity: number
  actual_quantity: number | null
  status: string
}

export interface SlaughterJournalResponse {
  orders: SlaughterOrderEntry[]
  summary: { total: number; done: number; planned: number; blocked: number; slaughtered: number; live_weight_kg: number }
  series?: SeriesPoint[]
  server_time: string
}

export interface MillProductionEntry {
  id: number
  batch_number: string
  formula: string | null
  quantity_produced: number
  status: string
  started_at: string | null
  created_at: string | null
}

export interface MillJournalResponse {
  productions: MillProductionEntry[]
  summary: { total: number; done: number; in_progress: number; planned: number; total_kg: number }
  series?: SeriesPoint[]
  server_time: string
}

export interface TreasuryMovement {
  id: number
  account: string | null
  direction: 'in' | 'out'
  amount: number
  category: string | null
  description: string | null
  created_at: string | null
}

export interface TreasuryAccountBalance {
  id: number
  name: string
  type: string
  balance: number
  is_active: boolean
}

export interface TreasuryJournalResponse {
  movements: TreasuryMovement[]
  summary: { in: number; out: number; net: number }
  accounts: TreasuryAccountBalance[]
  total_balance: number
  series?: SeriesPoint[]
  server_time: string
}

export interface BatchCheck {
  date: string | null
  weight: number | null
  mortality: number
  feed: number | null
  water: number | null
  health: string | null
}

export interface BatchHistoryResponse {
  batch: {
    id: number
    code: string
    status: string
    building: string | null
    age: number
    initial_quantity: number
    current_quantity: number
    total_mortality: number
    mortality_rate: number
    avg_weight_start: number | null
    latest_weight: number | null
    gmq: number | null
    is_gmq_tracked: boolean
  }
  checks: BatchCheck[]
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
  area_used_ha: string | number | null
  /**
   * Date à partir de laquelle la récolte redevient permise (délai avant
   * récolte / résidus phytosanitaires), ou null si rien ne la bloque.
   *
   * Le serveur refuse DÉFINITIVEMENT une récolte sous délai. Sans cette date au
   * terrain, le refus n'arrivait qu'à la synchronisation — donc après la
   * récolte, quand il ne servait plus qu'à perdre la saisie.
   */
  harvest_blocked_until?: string | null
  updated_at: string
}

export interface RefSlaughterOrder {
  id: number
  order_number: string
  batch_id: number | null
  planned_date: string
  planned_quantity: number
  status: string
  /** Clôture de cycle (checklist HACCP signée) — plus aucune activité ensuite. */
  closed_at?: string | null
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

/** Citerne / source d'eau — pour le ravitaillement terrain (type 'citerne'). */
export interface RefWaterSource {
  id: number
  name: string
  type: string
  capacity_liters: string | number | null
  current_level_liters: string | number | null
  current_level_percent: string | number | null
  is_active: boolean
  updated_at: string
}

export interface RefCropSpecies {
  id: number
  name: string
  local_name: string | null
  type: string | null
  updated_at: string
  /** Ce qu'on met en terre : semence, rejet, bouture… (null = semence). */
  planting_material?: string | null
  /** Comment on le compte : kg, unité… (null = kg). */
  planting_unit?: string | null
  /** Densité de référence par hectare — sert à PROPOSER la quantité. */
  planting_density?: number | null
  /** Poids moyen d'UNE unité récoltée (kg) — convertit un comptage en poids. */
  avg_unit_weight_kg?: string | number | null
  /** Nom de l'unité récoltée : fruit, régime, tubercule… */
  harvest_unit_label?: string | null
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
