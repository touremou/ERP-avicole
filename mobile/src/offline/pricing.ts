/**
 * Tarifs par palier client, appliqués HORS RÉSEAU.
 *
 * Le POS web résout les prix par AJAX (`sales.catalog-prices`) : impossible au
 * terrain. Ce module rejoue la MÊME cascade que
 * App\Models\SalePriceList::priceForProduct, depuis le miroir Dexie :
 *
 *   1. prix par ARTICLE du tarif du client,
 *   2. prix par CATÉGORIE du tarif du client,
 *   3. prix de base de l'article.
 *
 * Le tarif retenu est celui du client ; à défaut le tarif marqué « par défaut »
 * (client de comptoir). Le prix reste surchargeable à la ligne — c'est une
 * suggestion, pas un verrou.
 */
import { db } from './db'
import type { RefProduct, RefSalePriceList } from '../api/types'

export interface PriceBook {
  /** Tarif applicable (null = aucun tarif configuré → prix de base). */
  listId: number | null
  listName: string | null
  /** Prix par article : { product_id: prix }. */
  byProduct: Record<number, number>
  /** Prix par catégorie : { product_type: prix }. */
  byType: Record<string, number>
}

export const EMPTY_PRICE_BOOK: PriceBook = { listId: null, listName: null, byProduct: {}, byType: {} }

/**
 * Construit le barème applicable à un client (ou au comptoir si null).
 * Une seule lecture Dexie par changement de client — pas de requête réseau.
 */
export async function priceBookFor(clientId: number | null): Promise<PriceBook> {
  const [lists, client] = await Promise.all([
    db.ref_sale_price_lists.toArray(),
    clientId ? db.ref_clients.get(clientId) : Promise.resolve(undefined),
  ])

  const listId = resolveListId(client?.price_list_id ?? null, lists)
  if (listId === null) return EMPTY_PRICE_BOOK

  const items = await db.ref_sale_price_list_items
    .where('sale_price_list_id').equals(listId).toArray()

  const byProduct: Record<number, number> = {}
  const byType: Record<string, number> = {}
  for (const item of items) {
    if (item.product_id != null) byProduct[item.product_id] = Number(item.unit_price)
    else if (item.product_type) byType[item.product_type] = Number(item.unit_price)
  }

  return {
    listId,
    listName: lists.find((l) => l.id === listId)?.name ?? null,
    byProduct,
    byType,
  }
}

/** Tarif du client, sinon tarif par défaut, sinon aucun. */
function resolveListId(clientListId: number | null, lists: RefSalePriceList[]): number | null {
  // Le tarif du client doit être PRÉSENT en local : un id qui pointe une liste
  // non synchronisée donnerait un barème vide, donc des prix de base silencieux
  // — mieux vaut retomber explicitement sur le tarif par défaut.
  if (clientListId != null && lists.some((l) => l.id === clientListId)) return clientListId
  return lists.find((l) => l.is_default)?.id ?? null
}

/** Prix suggéré pour un article selon le barème (cascade article → type → base). */
export function priceFor(product: RefProduct, book: PriceBook): number {
  const perArticle = book.byProduct[product.id]
  if (perArticle != null) return perArticle

  const perType = book.byType[product.product_type]
  if (perType != null) return perType

  return Number(product.base_price) || 0
}

/** Aide au diagnostic terrain : d'où vient le prix affiché. */
export function priceOrigin(product: RefProduct, book: PriceBook): 'article' | 'categorie' | 'base' {
  if (book.byProduct[product.id] != null) return 'article'
  if (book.byType[product.product_type] != null) return 'categorie'
  return 'base'
}

/** Article vendu au poids → quantité décimale, pesée brut/tare, pas de 0,1. */
export function isWeighable(unit: string | null | undefined): boolean {
  return ['kg', 'g', 'l', 'litre', 'litres', 'tonne'].includes((unit ?? '').trim().toLowerCase())
}

/** Pas des boutons −/+ : 1 pour les pièces, 0,1 pour le pesé. */
export function stepFor(unit: string | null | undefined): number {
  return isWeighable(unit) ? 0.1 : 1
}

/** Arrondi de quantité : 3 décimales au poids (le gramme), entier à la pièce. */
export function roundQuantity(value: number, unit: string | null | undefined): number {
  if (!isWeighable(unit)) return Math.max(0, Math.round(value))
  return Math.max(0, Math.round(value * 1000) / 1000)
}

/**
 * Borne de stock : on arrondit toujours VERS LE BAS. Un stock de 5,5 « unités »
 * (dérive de données) autorise 5 pièces, pas 6 — un plafond qu'on arrondit à la
 * hausse n'est plus un plafond.
 */
export function floorQuantity(value: number, unit: string | null | undefined): number {
  if (value <= 0) return 0
  if (!isWeighable(unit)) return Math.floor(value)
  return Math.floor(value * 1000) / 1000
}

/** Une quantité fractionnaire n'a pas de sens à la pièce (2,5 œufs). */
export function isValidQuantity(value: number, unit: string | null | undefined): boolean {
  if (!(value > 0)) return false
  return isWeighable(unit) || Number.isInteger(value)
}
