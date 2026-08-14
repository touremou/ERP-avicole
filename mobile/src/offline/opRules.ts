/**
 * opRules — validation CÔTÉ CLIENT des opérations hors-ligne, MIROIR FIDÈLE des
 * validateurs serveur (SyncService). Objectif : bloquer une saisie invalide
 * AVANT la mise en file, plutôt que de la voir échouer au push et atterrir dans
 * « À corriger ».
 *
 * Principe de sûreté : on ne reflète QUE des règles que le serveur applique
 * aussi (mêmes noms de champs, mêmes bornes) → une saisie acceptée par le
 * serveur l'est forcément ici (aucun faux rejet). Les contrôles AUTORITAIRES
 * serveur (idempotence, stock suffisant au moment du push, permissions,
 * existence en base) restent côté serveur — c'est le rôle du bac « À corriger ».
 */
import type { OperationType } from '../api/types'

export class OpValidationError extends Error {
  constructor(public errors: string[]) {
    super(errors.join(' '))
    this.name = 'OpValidationError'
  }
}

type P = Record<string, unknown>
const num = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v))
const has = (p: P, k: string): boolean => p[k] !== null && p[k] !== undefined && p[k] !== ''
const today = () => new Date().toISOString().slice(0, 10)
const isFuture = (v: unknown): boolean => typeof v === 'string' && v.slice(0, 10) > today()

/** Vérifie un champ « id de référence » : présent, entier > 0. */
function reqId(p: P, k: string, label: string, e: string[]): void {
  const n = num(p[k])
  if (n === null || !Number.isInteger(n) || n <= 0) e.push(`${label} : sélection requise.`)
}

/** Champ numérique optionnel : si présent, doit respecter min/max. */
function optNum(p: P, k: string, label: string, e: string[], min?: number, max?: number): void {
  if (!has(p, k)) return
  const n = num(p[k])
  if (n === null || Number.isNaN(n)) { e.push(`${label} : nombre invalide.`); return }
  if (min !== undefined && n < min) e.push(`${label} : doit être ≥ ${min}.`)
  if (max !== undefined && n > max) e.push(`${label} : doit être ≤ ${max}.`)
}

/** Champ numérique requis avec min. */
function reqNum(p: P, k: string, label: string, e: string[], min: number): void {
  const n = num(p[k])
  if (n === null || Number.isNaN(n)) { e.push(`${label} : valeur requise.`); return }
  if (n < min) e.push(`${label} : doit être ≥ ${min}.`)
}

function reqStr(p: P, k: string, label: string, e: string[]): void {
  if (!has(p, k)) e.push(`${label} : requis.`)
}

function reqDate(p: P, k: string, label: string, e: string[], noFuture = false): void {
  if (!has(p, k)) { e.push(`${label} : date requise.`); return }
  if (noFuture && isFuture(p[k])) e.push(`${label} : ne peut pas être dans le futur.`)
}

function reqEnum(p: P, k: string, label: string, allowed: string[], e: string[]): void {
  if (!allowed.includes(String(p[k]))) e.push(`${label} : valeur non autorisée.`)
}

/** Numérique requis avec borne min ET max (ex. température). */
function reqNumRange(p: P, k: string, label: string, e: string[], min: number, max: number): void {
  const n = num(p[k])
  if (n === null || Number.isNaN(n)) { e.push(`${label} : valeur requise.`); return }
  if (n < min) e.push(`${label} : doit être ≥ ${min}.`)
  if (n > max) e.push(`${label} : doit être ≤ ${max}.`)
}

/** Requiert un objet OU un tableau NON vide (ex. `mesures` CCP = objet côté JS,
 *  tableau associatif côté PHP). Éviter Array.isArray seul → faux rejet. */
function reqFilled(p: P, k: string, label: string, e: string[]): void {
  const v = p[k]
  const empty =
    v === null || v === undefined ||
    (Array.isArray(v) && v.length === 0) ||
    (typeof v === 'object' && !Array.isArray(v) && Object.keys(v as object).length === 0)
  if (empty) e.push(`${label} : au moins une valeur requise.`)
}

type Validator = (p: P) => string[]

const RULES: Partial<Record<OperationType, Validator>> = {
  /*
   * MISE EN LOT HORS LIGNE — la seule opération qui n'avait AUCUNE règle ici.
   *
   * Le pire mode d'échec du travail hors ligne : le technicien saisit, la file
   * accepte, et le refus n'apparaît qu'à la synchronisation — des heures plus tard,
   * au bac « À corriger », alors qu'il a quitté le bâtiment depuis longtemps. Ces
   * règles sont le miroir de celles du serveur (SyncService::batchUpsert) et
   * refusent AVANT la mise en file, quand la correction ne coûte rien.
   *
   * L'écran (NewBatchScreen) porte ses propres contrôles, mais un contrôle d'écran
   * ne protège que cet écran-là. C'est ici qu'est la barrière commune.
   */
  'batch.upsert': (p) => {
    const e: string[] = []
    reqStr(p, 'code', 'Code du lot', e)
    reqStr(p, 'type', 'Type de production', e)
    reqId(p, 'building_id', 'Bâtiment', e)
    reqNum(p, 'initial_quantity', 'Effectif à l’arrivée', e, 1)
    reqDate(p, 'arrival_date', 'Date d’arrivée', e, true)
    optNum(p, 'current_quantity', 'Effectif actuel', e, 0)
    optNum(p, 'qty_dead', 'Morts à l’arrivée', e, 0)
    optNum(p, 'buy_price_per_unit', 'Prix d’achat unitaire', e, 0)

    // Le serveur n'accepte que ces deux valeurs (in:Actif,Terminé) : un statut
    // arbitraire ferait échouer la synchro pour un motif illisible sur le terrain.
    if (has(p, 'status')) reqEnum(p, 'status', 'Statut', ['Actif', 'Terminé'], e)

    // Cohérence propre au terrain : on ne peut pas déclarer plus de morts à
    // l'arrivée que de sujets reçus. Le serveur ne l'interdit pas explicitement,
    // mais la bande naîtrait avec un effectif négatif.
    const initial = num(p['initial_quantity'])
    const dead = num(p['qty_dead'])
    if (initial !== null && dead !== null && dead > initial) {
      e.push('Morts à l’arrivée : ne peut pas dépasser l’effectif reçu.')
    }

    return e
  },
  'daily_check.create': (p) => {
    const e: string[] = []
    reqId(p, 'batch_id', 'Lot', e)
    reqDate(p, 'check_date', 'Date', e)
    optNum(p, 'mortality', 'Mortalité', e, 0)
    optNum(p, 'avg_weight', 'Poids moyen', e, 0)
    optNum(p, 'water_consumed', 'Eau', e, 0)
    optNum(p, 'feed_consumed', 'Aliment', e, 0)
    optNum(p, 'humidity', 'Humidité', e, 0, 100)
    return e
  },
  'egg_collection.create': (p) => {
    const e: string[] = []
    reqId(p, 'batch_id', 'Lot', e)
    reqDate(p, 'production_date', 'Date', e, true)
    reqNum(p, 'total_eggs_collected', 'Œufs collectés', e, 0)
    optNum(p, 'broken_eggs', 'Œufs cassés', e, 0)
    optNum(p, 'small_eggs', 'Petits œufs', e, 0)
    return e
  },
  'stock_movement.create': (p) => {
    const e: string[] = []
    reqId(p, 'stock_id', 'Article', e)
    reqEnum(p, 'type', 'Type de mouvement', ['in', 'out', 'adjustment'], e)
    reqNum(p, 'quantity', 'Quantité', e, 0.001)
    return e
  },
  'sale.create': (p) => {
    const e: string[] = []
    reqId(p, 'client_id', 'Client', e)
    reqDate(p, 'sale_date', 'Date', e, true)
    reqEnum(p, 'type', 'Type', ['bon_livraison', 'facture'], e)
    if (!Array.isArray(p.items) || p.items.length === 0) e.push('Articles : au moins une ligne requise.')
    return e
  },
  'expense.create': (p) => {
    const e: string[] = []
    reqStr(p, 'category', 'Catégorie', e)
    reqStr(p, 'label', 'Libellé', e)
    reqNum(p, 'amount', 'Montant', e, 1)
    reqDate(p, 'expense_date', 'Date', e, true)
    return e
  },
  'incubation.mirage': (p) => {
    const e: string[] = []
    reqId(p, 'incubation_id', 'Cycle', e)
    reqNum(p, 'fertile_eggs', 'Œufs fertiles', e, 0)
    return e
  },
  'incubation.hatch': (p) => {
    const e: string[] = []
    reqId(p, 'incubation_id', 'Cycle', e)
    reqNum(p, 'hatched_chicks', 'Poussins éclos', e, 0)
    return e
  },
  'milk_production.create': (p) => {
    const e: string[] = []
    reqId(p, 'batch_id', 'Lot', e)
    reqDate(p, 'production_date', 'Date', e, true)
    optNum(p, 'morning_liters', 'Traite du matin', e, 0)
    optNum(p, 'evening_liters', 'Traite du soir', e, 0)
    const total = (num(p.morning_liters) ?? 0) + (num(p.evening_liters) ?? 0)
    if (total <= 0) e.push('Renseignez au moins une traite (matin ou soir).')
    return e
  },
  'energy_reading.create': (p) => {
    const e: string[] = []
    reqId(p, 'energy_source_id', 'Source', e)
    reqDate(p, 'reading_date', 'Date', e, true)
    reqNumRange(p, 'hours_run', 'Heures de marche', e, 0, 24)
    optNum(p, 'outage_hours', 'Heures de coupure', e, 0, 24)
    return e
  },
  'water_reading.create': (p) => {
    const e: string[] = []
    reqId(p, 'water_source_id', 'Citerne', e)
    reqDate(p, 'reading_date', 'Date', e, true)
    reqNum(p, 'volume_consumed_liters', 'Volume consommé', e, 0)
    optNum(p, 'quality_ph', 'pH', e, 0, 14)
    optNum(p, 'chlorine_level', 'Chlore', e, 0, 10)
    return e
  },
  'attendance.create': (p) => {
    const e: string[] = []
    reqDate(p, 'attendance_date', 'Date', e, true)
    const rows = Array.isArray(p.rows) ? (p.rows as Array<Record<string, unknown>>) : []
    if (rows.length === 0) e.push('Présence : au moins un employé requis.')
    const allowed = ['present', 'retard', 'absent', 'conge']
    if (rows.some((r) => !allowed.includes(String(r.status)))) {
      e.push('Statut de présence invalide.')
    }
    if (rows.some((r) => !Number.isFinite(Number(r.employee_id)) || Number(r.employee_id) <= 0)) {
      e.push('Employé : identifiant manquant.')
    }
    const ids = rows.map((r) => Number(r.employee_id))
    if (new Set(ids).size !== ids.length) e.push('Un employé apparaît deux fois dans la grille.')
    return e
  },
  'mill_production.create': (p) => {
    const e: string[] = []
    reqId(p, 'formula_id', 'Formule', e)
    reqId(p, 'supervisor_id', 'Superviseur', e)
    reqNum(p, 'nb_bags', 'Nombre de sacs', e, 1)
    const machines = Array.isArray(p.machine_ids) ? p.machine_ids : []
    if (machines.length === 0) e.push('Machine : au moins une sélection requise.')
    return e
  },
  'inventory_count.create': (p) => {
    const e: string[] = []
    reqId(p, 'stock_id', 'Article', e)
    reqNum(p, 'counted_quantity', 'Quantité comptée', e, 0)
    reqDate(p, 'count_date', 'Date', e, true)
    return e
  },
  'feed_purchase.create': (p) => {
    const e: string[] = []
    reqId(p, 'batch_id', 'Lot destinataire', e)
    reqDate(p, 'purchase_date', 'Date', e, true)
    reqStr(p, 'feed_type', 'Type d\'aliment', e)
    reqNum(p, 'quantity', 'Quantité', e, 0.001)
    reqNum(p, 'unit_price', 'Montant total', e, 0)
    reqStr(p, 'unit', 'Unité', e)
    return e
  },
  'payment.create': (p) => {
    const e: string[] = []
    reqId(p, 'sale_id', 'Vente', e)
    reqNum(p, 'amount', 'Montant', e, 1)
    reqDate(p, 'payment_date', 'Date', e, true)
    reqStr(p, 'method', 'Mode de paiement', e)
    return e
  },
  'sale_return.create': (p) => {
    const e: string[] = []
    reqId(p, 'sale_id', 'Vente', e)
    reqStr(p, 'refund_method', 'Mode de remboursement', e)
    const lines = Array.isArray(p.lines) ? (p.lines as Array<Record<string, unknown>>) : []
    if (lines.length === 0) e.push('Au moins une ligne à retourner est requise.')
    if (lines.some((l) => !(num(l.quantity) && (num(l.quantity) as number) > 0))) {
      e.push('Quantité retournée invalide.')
    }
    return e
  },
  'health_check.create': (p) => {
    const e: string[] = []
    reqId(p, 'batch_id', 'Lot', e)
    reqDate(p, 'intervention_date', 'Date', e, true)
    reqStr(p, 'type', 'Type', e)
    reqStr(p, 'product_name', 'Produit', e)
    reqStr(p, 'mode_administration', 'Mode d\'administration', e)
    // Produit périmé au jour de l'intervention : refus AVANT la file
    // (miroir du garde-fou serveur — inutile de partir pour être rejeté).
    const expiry = typeof p.expiry_date === 'string' ? p.expiry_date : null
    const day = typeof p.intervention_date === 'string' ? p.intervention_date : null
    if (expiry && day && expiry < day) e.push('Produit périmé au jour de l\'intervention.')
    return e
  },
  'health_incident.create': (p) => {
    const e: string[] = []
    reqId(p, 'batch_id', 'Lot', e)
    reqDate(p, 'incident_date', 'Date', e, true)
    reqNum(p, 'mortality_count', 'Mortalité', e, 0)
    reqStr(p, 'symptoms', 'Symptômes', e)
    return e
  },
  'crop_cycle.create': (p) => {
    const e: string[] = []
    reqId(p, 'plot_id', 'Parcelle', e)
    reqStr(p, 'crop_name', 'Culture', e)
    reqNum(p, 'area_used_ha', 'Surface semée', e, 0.001)
    reqDate(p, 'planting_date', 'Date de semis', e, true)
    optNum(p, 'seed_quantity', 'Quantité de semence', e, 0)
    return e
  },
  'harvest.create': (p) => {
    const e: string[] = []
    reqId(p, 'crop_cycle_id', 'Cycle', e)
    reqDate(p, 'harvest_date', 'Date', e, true)
    reqNum(p, 'quantity', 'Quantité', e, 0.001)
    // Destination (T1) : miroir de RecordHarvest — une récolte conservée doit
    // être pesée en kg, sinon elle sort du revenu sans pouvoir être valorisée.
    const dest = String(p.destination ?? 'vente')
    if (!['vente', 'transformation', 'stockage'].includes(dest)) {
      e.push('Destination de récolte invalide.')
    }
    if (dest !== 'vente') {
      const kg = Number(p.net_weight_kg ?? 0)
      const inKg = String(p.unit ?? 'kg').trim().toLowerCase() === 'kg'
      if (!(kg > 0) && !(inKg && Number(p.quantity) > 0)) {
        e.push('Récolte non vendue : le poids net en kg est obligatoire (il la valorise en stock).')
      }
    }
    return e
  },
  'stored_lot.check': (p) => {
    const e: string[] = []
    reqId(p, 'stored_lot_id', 'Lot en conservation', e)
    const conditions = ['bon', 'humide', 'insectes', 'moisissure', 'degrade']
    if (!conditions.includes(String(p.condition))) e.push('État constaté invalide.')
    // Miroir de RecordStoredLotCheck : un constat grave sans décision est refusé.
    // Un contrôle qui voit un problème et ne décide rien sert d'alibi.
    const grave = ['insectes', 'moisissure', 'degrade']
    if (grave.includes(String(p.condition)) && (p.action_taken ?? 'aucune') === 'aucune') {
      e.push('Constat grave : une décision est obligatoire (séchage, traitement, déclassement ou destruction).')
    }
    optNum(p, 'weighed_quantity', 'Pesée', e, 0)
    optNum(p, 'market_price', 'Cours du marché', e, 0)
    return e
  },
  'crop_transformation.create': (p) => {
    const e: string[] = []
    reqStr(p, 'input_product', 'Produit entrant', e)
    reqStr(p, 'output_product', 'Produit fini', e)
    reqStr(p, 'transformation_type', 'Type de transformation', e)
    reqNum(p, 'input_quantity', 'Quantité engagée', e, 0.001)
    reqNum(p, 'output_quantity', 'Quantité obtenue', e, 0)
    reqDate(p, 'production_date', 'Date', e, true)
    // Conservation de matière : le miroir de la garde serveur. Une sortie
    // supérieure à l'entrée dans la MÊME unité est une erreur de pesée.
    const inQty = Number(p.input_quantity) || 0
    const outQty = Number(p.output_quantity) || 0
    const sameUnit = String(p.input_unit ?? 'kg').trim().toLowerCase()
      === String(p.output_unit ?? 'kg').trim().toLowerCase()
    if (inQty > 0 && sameUnit && outQty > inQty * 1.5) {
      e.push('Rendement aberrant : la sortie dépasse l\'entrée. Vérifiez les deux pesées.')
    }
    return e
  },
  'crop_input.create': (p) => {
    const e: string[] = []
    reqId(p, 'crop_cycle_id', 'Cycle', e)
    reqStr(p, 'name', 'Nom', e)
    reqDate(p, 'input_date', 'Date', e, true)
    return e
  },
  'slaughter.execute': (p) => {
    const e: string[] = []
    reqId(p, 'slaughter_order_id', 'Ordre', e)
    reqDate(p, 'execution_date', 'Date', e, true)
    reqNum(p, 'actual_quantity', 'Sujets abattus', e, 1)
    reqNum(p, 'total_live_weight_kg', 'Poids vif', e, 0.1)
    reqNum(p, 'total_carcass_weight_kg', 'Poids carcasse', e, 0.1)
    const live = num(p.total_live_weight_kg)
    const carc = num(p.total_carcass_weight_kg)
    if (live !== null && carc !== null && carc > live) e.push('Poids carcasse : ne peut pas dépasser le poids vif.')
    return e
  },
  'slaughter.cutting': (p) => {
    const e: string[] = []
    reqId(p, 'slaughter_order_id', 'Ordre', e)
    reqDate(p, 'session_date', 'Date', e, true)
    reqNum(p, 'total_input_kg', 'Poids entré', e, 0.1)
    const products = Array.isArray(p.products) ? (p.products as Array<Record<string, unknown>>) : []
    if (products.length === 0) e.push('Au moins un produit de découpe est requis.')
    // Conservation de matière : Σ morceaux ≤ poids entré (miroir serveur).
    const input = num(p.total_input_kg)
    const out = products.reduce((s, prod) => s + (num(prod.kg) ?? 0), 0)
    if (input !== null && out > input + 0.001) e.push('Le total des morceaux dépasse le poids entré.')
    return e
  },
  'slaughter.close': (p) => {
    const e: string[] = []
    reqId(p, 'slaughter_order_id', 'Ordre', e)
    // Les 3 confirmations sont OBLIGATOIRES (miroir serveur : accepted).
    if (p.waste_evacuated !== true) e.push('Confirmez l\'évacuation des déchets.')
    if (p.zone_cleaned !== true) e.push('Confirmez le nettoyage/désinfection.')
    if (p.marche_avant !== true) e.push('Confirmez le respect de la marche en avant.')
    return e
  },
  'water_refill.create': (p) => {
    const e: string[] = []
    reqId(p, 'water_source_id', 'Citerne', e)
    reqNum(p, 'volume_added_liters', 'Volume ajouté', e, 1)
    reqDate(p, 'refill_date', 'Date', e, true)
    return e
  },
  'task.start': (p) => {
    const e: string[] = []
    reqId(p, 'task_id', 'Tâche', e)
    return e
  },
  'task.release': (p) => {
    const e: string[] = []
    reqId(p, 'task_id', 'Tâche', e)
    return e
  },
  'task.complete': (p) => {
    const e: string[] = []
    reqId(p, 'task_id', 'Tâche', e)
    // La preuve (photo via photo_uuid, ou valeur chiffrée) est optionnelle au
    // niveau de l'op — son CARACTÈRE OBLIGATOIRE dépend du type de tâche et est
    // garanti par l'UI (modale) + revérifié serveur (autoritaire).
    optNum(p, 'proof_value', 'Valeur de preuve', e, 0)
    return e
  },
  'task.create': (p) => {
    const e: string[] = []
    reqStr(p, 'title', 'Intitulé', e)
    reqStr(p, 'category', 'Catégorie', e)
    reqDate(p, 'scheduled_date', 'Échéance', e)
    return e
  },
  // ── HACCP abattoir + provenderie (énumérations laissées au serveur/formulaire :
  //    on ne vérifie que présence / bornes / dates — zéro faux rejet). ──
  'mill_production.complete': (p) => {
    const e: string[] = []
    reqId(p, 'mill_production_id', 'Ordre de production', e)
    return e
  },
  'slaughter_reception.create': (p) => {
    const e: string[] = []
    reqId(p, 'provider_id', 'Fournisseur', e)
    reqDate(p, 'reception_date', 'Date', e, true)
    reqNum(p, 'received_quantity', 'Sujets reçus', e, 1)
    reqNum(p, 'total_live_weight_kg', 'Poids vif', e, 0.1)
    reqStr(p, 'sanitary_state', 'État sanitaire', e)
    reqStr(p, 'fasting_respected', 'Jeûne', e)
    reqStr(p, 'decision', 'Décision', e)
    optNum(p, 'announced_quantity', 'Sujets annoncés', e, 0)
    optNum(p, 'rejected_quantity', 'Sujets refusés', e, 0)
    optNum(p, 'purchase_unit_price', 'Prix d\'achat', e, 0)
    const rec = num(p.received_quantity)
    const rej = num(p.rejected_quantity)
    if (rec !== null && rej !== null && rej > rec) e.push('Sujets refusés : ne peut pas dépasser les reçus.')
    if (has(p, 'decision') && String(p.decision) !== 'accepte' && !has(p, 'decision_reason')) {
      e.push('Motif de la décision : requis sauf si accepté.')
    }
    return e
  },
  'ccp_record.create': (p) => {
    const e: string[] = []
    reqStr(p, 'ccp', 'CCP', e)
    reqFilled(p, 'mesures', 'Mesures', e)
    reqDate(p, 'releve_at', 'Date du relevé', e)
    return e
  },
  'temperature_log.create': (p) => {
    const e: string[] = []
    reqStr(p, 'point', 'Point de mesure', e)
    reqNumRange(p, 'temperature', 'Température', e, -60, 120)
    reqDate(p, 'releve_at', 'Date du relevé', e)
    return e
  },
  'cleaning_log.create': (p) => {
    const e: string[] = []
    reqStr(p, 'zone', 'Zone', e)
    reqStr(p, 'product_used', 'Produit', e)
    reqDate(p, 'done_at', 'Date', e)
    return e
  },
  'byproduct.create': (p) => {
    const e: string[] = []
    reqStr(p, 'type', 'Type', e)
    reqNum(p, 'quantity_kg', 'Quantité (kg)', e, 0.01)
    reqStr(p, 'destination', 'Destination', e)
    reqDate(p, 'collected_at', 'Date', e)
    return e
  },
}

/** Renvoie la liste des erreurs (vide si valide). */
export function validateOp(type: OperationType, payload: Record<string, unknown>): string[] {
  const rule = RULES[type]
  return rule ? rule(payload) : []
}
