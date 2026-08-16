/**
 * CONTRÔLE D'ACCÈS DE LA PWA — deux tables, une seule règle d'évaluation.
 *
 * Le problème corrigé ici : les tuiles des hubs (accueil, « Nouvelle saisie »)
 * étaient bien filtrées par `can(module, level)`, mais RIEN ne gardait
 *   1. les ROUTES — /abattoir/ccp s'ouvrait à qui tapait l'URL (ou revenait
 *      dessus par l'historique, ou suivait un lien mis en favori), et
 *   2. l'OUTBOX — l'écran ouvert, la saisie partait en file, montait au
 *      serveur, se faisait refuser (`permission_denied`) et atterrissait dans
 *      « À corriger ». L'agent voyait une erreur incompréhensible pour une
 *      chose qu'il n'avait jamais eu le droit de faire.
 *
 * D'où deux tables EXHAUSTIVES (`Record<OperationType, …>` force le
 * compilateur à refuser un nouvel op sans droit déclaré ; RbacMobileAccessTest
 * verrouille la même exhaustivité côté serveur) :
 *
 *   OP_ACCESS    — miroir des `Gate::denies()` de SyncService. Vérifié DANS
 *                  enqueue() : une op non autorisée n'entre jamais en file.
 *   ROUTE_ACCESS — droit exigé par chaque écran. Consommé par App.tsx.
 *
 * Le serveur reste l'autorité : ces tables sont un miroir hors-ligne (la
 * matrice `me.permissions` est en cache Dexie). Elles évitent le voyage
 * inutile et l'écran ouvert pour rien — elles ne remplacent pas le contrôle
 * serveur, qui re-vérifie chaque op au push.
 */
import type { OperationType, PermissionLevel } from '../api/types'

/**
 * Droit exigé, sous une forme lisible dans les tables :
 *   'cultures.C'                  — ce module à ce niveau
 *   'provenderie.C|logistique.C'  — l'UN des deux suffit (miroir des gates
 *                                   « any-of » du serveur)
 *   '@owner'                      — portée PERSONNELLE : il faut une fiche
 *                                   employé, pas un droit de module. Un
 *                                   technicien de cultures gère ses tâches
 *                                   sans aucun droit RH (c'est le serveur qui
 *                                   vérifie ensuite qu'il s'agit bien des
 *                                   SIENNES).
 *   '@self'                       — son propre compte / son appareil : ouvert
 *                                   à tout utilisateur connecté. Choix
 *                                   EXPLICITE et donc visible en revue, au
 *                                   lieu d'un silence qui ressemble à un oubli.
 */
export type AccessSpec = string

export interface AccessContext {
  /** Matrice de permissions en cache (AuthContext.can). */
  can: (module: string, level: PermissionLevel) => boolean
  /** Fiche employé rattachée au compte (me.scope.employee_id). */
  hasEmployee: boolean
}

export function allows(spec: AccessSpec, ctx: AccessContext): boolean {
  if (spec === '@self') return true
  if (spec === '@owner') return ctx.hasEmployee

  // Une seule des alternatives suffit — comme `Gate::denies(a) && Gate::denies(b)`.
  return spec.split('|').some((one) => {
    const [module, level] = one.split('.')
    return ctx.can(module, level as PermissionLevel)
  })
}

/**
 * Droit exigé par chaque opération — MIROIR de SyncService::types() et des
 * `Gate::denies()` de chaque handler. Toute divergence est un bug : soit
 * l'agent voit un formulaire qui sera refusé, soit on lui refuse ici un droit
 * que le serveur lui accorde.
 *
 * Les ops '@owner' sont celles que le serveur autorise au TITULAIRE sans droit
 * de module : gérer sa propre liste de tâches. Le serveur y vérifie la
 * propriété réelle (ou rh.M pour un superviseur) ; ici, on exige seulement
 * d'avoir une fiche employé, car sans elle le serveur refuse de toute façon.
 */
export const OP_ACCESS: Record<OperationType, AccessSpec> = {
  'daily_check.create': 'elevage.C',
  'egg_collection.create': 'production.C',
  'stock_movement.create': 'logistique.M',
  'water_refill.create': 'ressources.C',
  'sale.create': 'commerce.C',
  'payment.create': 'commerce.C',
  'sale_return.create': 'commerce.M',
  'inventory_count.create': 'logistique.C',
  'feed_purchase.create': 'provenderie.C|logistique.C',
  'mill_production.create': 'provenderie.C',
  'mill_production.complete': 'provenderie.M',
  'incubation.mirage': 'production.M',
  'incubation.hatch': 'production.M',
  'milk_production.create': 'production.C',
  'energy_reading.create': 'ressources.C',
  'water_reading.create': 'ressources.C',
  'attendance.create': 'rh.C',
  'expense.create': 'depenses.C',
  // Le serveur exige elevage.M sur un lot EXISTANT, elevage.C à la création :
  // on retient le droit minimal (C), la création étant le cas du terrain.
  'batch.upsert': 'elevage.C',
  'health_incident.create': 'elevage.C',
  'health_check.create': 'elevage.C',
  'crop_cycle.create': 'cultures.C',
  'harvest.create': 'cultures.C',
  'crop_input.create': 'cultures.C',
  'crop_transformation.create': 'cultures.C',
  'stored_lot.check': 'logistique.C',
  'slaughter.execute': 'abattoir.M',
  'slaughter.close': 'abattoir.M',
  'slaughter.cutting': 'abattoir.C',
  'slaughter_reception.create': 'abattoir.C',
  'ccp_record.create': 'abattoir.C',
  'temperature_log.create': 'abattoir.C',
  'cleaning_log.create': 'abattoir.C',
  'byproduct.create': 'abattoir.C',
  'task.complete': '@owner',
  'task.start': '@owner',
  'task.release': '@owner',
  'task.create': '@owner',
  // AFFECTER à un collègue (ou au pool) est un geste d'encadrement, pas de
  // saisie personnelle : le serveur exige rh.C (SyncService::taskDispatch),
  // exactement comme le formulaire du bureau. Le déclarer ici évite qu'un agent
  // voie un écran dont l'envoi sera refusé.
  'task.dispatch': 'rh.C',
}

/**
 * Droit exigé par chaque écran. La clé est le `path` du <Route> — App.tsx
 * consomme cette table, ce qui rend impossible d'ajouter une route sans
 * déclarer son droit (le tableau est typé).
 *
 * RÈGLE : le droit d'un écran de SAISIE est celui de l'op qu'il produit. Un
 * écran de consultation exige le niveau L du module qu'il lit — sauf « mon
 * espace », « mes tâches » et « mes appareils », qui sont personnels.
 */
export const ROUTE_ACCESS = {
  // Hubs : filtrent eux-mêmes leurs tuiles, et un compte sans aucun droit doit
  // pouvoir atteindre son espace personnel.
  '/': '@self',
  '/nouvelle': '@self',
  '/mon-espace': '@self',
  '/mon-espace/semaine': '@self',
  '/appareils': '@self',
  '/alertes': '@self',
  '/taches': '@owner',
  // Affecter à autrui n'est pas une saisie personnelle : même droit que l'op
  // qu'il produit (règle énoncée ci-dessus).
  '/taches/affecter': 'rh.C',

  // Élevage
  '/elevage/pointage/:batchId?': 'elevage.C',
  '/elevage/incident/:batchId': 'elevage.C',
  '/elevage/soin/:batchId?': 'elevage.C',
  // Mise en lot depuis le terrain : même droit que le type d'opération qu'elle
  // émet (`batch.upsert` → elevage.C). Un écran plus permissif que son opération
  // ferait remplir un formulaire pour rien.
  '/elevage/mise-en-lot': 'elevage.C',
  '/lot/:batchId': 'elevage.L',
  '/scan': 'elevage.L|production.L',

  // Production (œufs, couvoir, lait)
  '/elevage/collecte/:batchId': 'production.C',
  '/elevage/couvoir/:incubationId?': 'production.M',
  '/elevage/traite/:batchId?': 'production.C',

  // Commerce
  '/commerce/vente': 'commerce.C',
  '/commerce/journal': 'commerce.L',
  '/commerce/encaisser/:saleId?': 'commerce.C',
  '/commerce/retour/:saleId?': 'commerce.M',

  // Trésorerie (consultation seule au terrain)
  '/tresorerie/journal': 'tresorerie.L',

  // Logistique
  '/logistique/mouvement': 'logistique.M',
  '/logistique/stocks': 'logistique.L',
  '/logistique/inventaire': 'logistique.C',
  '/logistique/reception-aliment': 'provenderie.C|logistique.C',
  '/logistique/conservation/:lotId?': 'logistique.C',

  // Ressources (eau & énergie)
  '/ressources/ravitaillement': 'ressources.C',
  '/ressources/releve': 'ressources.C',

  // RH
  '/rh/presence': 'rh.C',

  // Dépenses
  '/depenses/nouvelle': 'depenses.C',

  // Cultures
  '/cultures/semis': 'cultures.C',
  '/cultures/recolte/:cycleId': 'cultures.C',
  '/cultures/intrant/:cycleId': 'cultures.C',
  '/cultures/transformation': 'cultures.C',
  '/cultures/journal': 'cultures.L',

  // Abattoir
  '/abattoir/execution/:orderId': 'abattoir.M',
  '/abattoir/cloture/:orderId': 'abattoir.M',
  '/abattoir/decoupe': 'abattoir.C',
  '/abattoir/reception': 'abattoir.C',
  '/abattoir/temperature': 'abattoir.C',
  '/abattoir/temperature/tournee': 'abattoir.C',
  '/abattoir/ccp': 'abattoir.C',
  '/abattoir/nettoyage': 'abattoir.C',
  '/abattoir/nettoyage/tournee': 'abattoir.C',
  '/abattoir/sousproduit': 'abattoir.C',
  '/abattoir/sousproduit/tournee': 'abattoir.C',
  '/abattoir/journal': 'abattoir.L',

  // Provenderie
  '/provenderie/lancer': 'provenderie.C',
  '/provenderie/cloture/:opId': 'provenderie.M',
  '/provenderie/journal': 'provenderie.L',
} as const satisfies Record<string, AccessSpec>

/**
 * Chemins DÉCLARÉS. App.tsx type son tableau d'écrans avec ce type : ajouter un
 * <Route> sans l'inscrire ci-dessus ne compile pas. C'est la garantie
 * structurelle — pas une convention à se rappeler — qu'aucun écran ne
 * réapparaisse sans droit.
 */
export type RoutePath = keyof typeof ROUTE_ACCESS

/** Levée par enqueue() quand l'op dépasse les droits du compte. */
export class OpForbiddenError extends Error {
  constructor(public readonly type: OperationType) {
    super(`Opération non autorisée : ${type}`)
    this.name = 'OpForbiddenError'
  }
}

/**
 * Droit exigé par un chemin CONCRET (« /lot/12 »), en le rapprochant du gabarit
 * déclaré (« /lot/:batchId »).
 *
 * Sert aux destinations d'alerte : une notification peut désigner un écran que
 * le profil n'a pas le droit d'ouvrir — le promoteur reçoit l'alerte de
 * mortalité, le vendeur non. Sans ce contrôle, le clic mène à « Accès refusé »,
 * ce qui se lit comme une panne alors que c'est une règle.
 *
 * Renvoie null si le chemin ne correspond à aucune route : mieux vaut ne pas
 * naviguer que d'atterrir sur l'accueil sans explication.
 */
export function accessForPath(path: string): AccessSpec | null {
  const target = path.split('?')[0].replace(/\/+$/, '') || '/'

  for (const [pattern, spec] of Object.entries(ROUTE_ACCESS)) {
    const segments = pattern.split('/')
    const parts = target.split('/')

    // Un segment optionnel (« :id? ») autorise un chemin plus court d'un cran.
    const optional = segments.filter((s) => s.endsWith('?')).length
    if (parts.length > segments.length || parts.length < segments.length - optional) continue

    const matches = segments.every((segment, i) => {
      if (segment.startsWith(':')) return true
      return segment === parts[i]
    })

    if (matches) return spec as AccessSpec
  }

  return null
}
