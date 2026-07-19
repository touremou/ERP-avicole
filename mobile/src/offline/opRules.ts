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
  'health_incident.create': (p) => {
    const e: string[] = []
    reqId(p, 'batch_id', 'Lot', e)
    reqDate(p, 'incident_date', 'Date', e, true)
    reqNum(p, 'mortality_count', 'Mortalité', e, 0)
    reqStr(p, 'symptoms', 'Symptômes', e)
    return e
  },
  'harvest.create': (p) => {
    const e: string[] = []
    reqId(p, 'crop_cycle_id', 'Cycle', e)
    reqDate(p, 'harvest_date', 'Date', e, true)
    reqNum(p, 'quantity', 'Quantité', e, 0.001)
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
  'water_refill.create': (p) => {
    const e: string[] = []
    reqId(p, 'water_source_id', 'Citerne', e)
    reqNum(p, 'volume_added_liters', 'Volume ajouté', e, 1)
    reqDate(p, 'refill_date', 'Date', e, true)
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
