/**
 * Vente rapide — créée en BROUILLON (bon de livraison) : la validation et le
 * déstockage restent des opérations EN LIGNE (matrice de conflits, RFC §3.3).
 *
 * Parité POS (M6) — ce qui manquait pour vendre pour de vrai au terrain :
 *  - TARIF DU CLIENT : la cascade article → catégorie → prix de base est
 *    rejouée hors réseau (offline/pricing.ts), là où le web appelle un AJAX.
 *    Vendre au prix détail à un grossiste, c'est perdre de l'argent à chaque
 *    ligne ;
 *  - PLU : le code article se tape au pavé, comme sur une balance ;
 *  - PESÉE brut − tare : une découpe se pèse dans un bac, pas à la pièce ;
 *  - QUANTITÉS DÉCIMALES clampées au stock : 0,4 kg de filet en stock → 0,4 au
 *    panier, jamais 1 (survente).
 *
 * Contrat : SyncService::saleCreate (gate commerce.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { safeLoad } from '../../offline/safeLoad'
import { enqueue } from '../../offline/sync'
import {
  EMPTY_PRICE_BOOK, floorQuantity, isValidQuantity, isWeighable, priceBookFor, priceFor,
  roundQuantity, stepFor, type PriceBook,
} from '../../offline/pricing'
import { t, dateLocale } from '../../i18n'
import type { RefClient, RefProduct } from '../../api/types'

interface SaleItem {
  product_id: number
  product_name: string
  product_type: string
  unit: string
  quantity: number
  unit_price: number
  /** Stock vendable au moment de l'ajout (null = article non suivi). */
  max: number | null
}

/** Quantité déjà réservée au panier pour un article (hors ligne en cours d'édition). */
function inCart(items: SaleItem[], productId: number): number {
  return items.filter((i) => i.product_id === productId).reduce((sum, i) => sum + i.quantity, 0)
}

/** Reste vendable : null = article non suivi en stock (vendable librement). */
function remaining(product: RefProduct, items: SaleItem[]): number | null {
  const stock = product.available_quantity
  if (stock === null || stock === undefined) return null
  return floorQuantity(stock - inCart(items, product.id), product.unit)
}

export function SaleScreen() {
  const navigate = useNavigate()
  const [clients, setClients] = useState<RefClient[]>([])
  const [products, setProducts] = useState<RefProduct[]>([])
  const [clientId, setClientId] = useState('')
  const [book, setBook] = useState<PriceBook>(EMPTY_PRICE_BOOK)
  const [items, setItems] = useState<SaleItem[]>([])

  // Ligne en cours de saisie.
  const [search, setSearch] = useState('')
  const [productId, setProductId] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [unitPrice, setUnitPrice] = useState('')
  const [priceTouched, setPriceTouched] = useState(false)
  const [gross, setGross] = useState('')
  const [tare, setTare] = useState('')
  const [soldOut, setSoldOut] = useState<string | null>(null)

  const [immediatePayment, setImmediatePayment] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('especes')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void safeLoad('vente:clients', async () => setClients(await db.ref_clients.orderBy('name').toArray()))
    void db.ref_products.filter((p) => p.is_active).toArray().then(setProducts)
  }, [])

  // Changement de client → tout l'écran se re-tarife, y compris le panier déjà
  // constitué (sauf les prix saisis à la main, qu'on ne va pas écraser).
  useEffect(() => {
    let cancelled = false
    void priceBookFor(clientId ? Number(clientId) : null).then((next) => {
      if (cancelled) return
      setBook(next)
      setItems((current) =>
        current.map((item) => {
          const product = products.find((p) => p.id === item.product_id)
          return product ? { ...item, unit_price: priceFor(product, next) } : item
        }),
      )
    })
    return () => { cancelled = true }
  }, [clientId, products])

  const selectedProduct = useMemo(
    () => products.find((p) => p.id === Number(productId)),
    [products, productId],
  )

  // Recherche par NOM ou par CODE PLU (sku) — comme le POS web.
  const matches = useMemo(() => {
    const q = search.trim().toLowerCase()
    const sorted = [...products].sort((a, b) => {
      // Favoris d'abord (touches rapides de la balance), puis alphabétique.
      if (Boolean(a.is_favorite) !== Boolean(b.is_favorite)) return a.is_favorite ? -1 : 1
      return a.name.localeCompare(b.name, 'fr')
    })
    if (!q) return sorted
    return sorted.filter(
      (p) => p.name.toLowerCase().includes(q) || (p.sku ?? '').toLowerCase().includes(q),
    )
  }, [products, search])

  // Prix suggéré du tarif applicable — écrasé seulement si l'opérateur n'a pas
  // déjà saisi un prix à la main pour cette ligne.
  useEffect(() => {
    if (!selectedProduct) return
    if (priceTouched) return
    setUnitPrice(String(priceFor(selectedProduct, book)))
  }, [selectedProduct, book, priceTouched])

  const unit = selectedProduct?.unit ?? null
  const weighable = isWeighable(unit)
  const step = stepFor(unit)

  // Stock encore vendable : ce qui reste après ce qui est déjà au panier.
  const available = useMemo(
    () => (selectedProduct ? remaining(selectedProduct, items) : null),
    [selectedProduct, items],
  )

  const net = useMemo(() => {
    const value = (Number(gross) || 0) - (Number(tare) || 0)
    return value > 0 ? roundQuantity(value, unit) : 0
  }, [gross, tare, unit])

  const qty = Number(quantity) || 0
  const overStock = available !== null && qty > available
  // 2,5 œufs n'existent pas : on refuse plutôt que d'arrondir en douce.
  const fractional = qty > 0 && !isValidQuantity(qty, unit)
  const canAdd = Boolean(selectedProduct) && qty > 0 && unitPrice !== '' && !overStock && !fractional

  function resetLine() {
    setProductId('')
    setSearch('')
    setSoldOut(null)
    setQuantity('1')
    setUnitPrice('')
    setPriceTouched(false)
    setGross('')
    setTare('')
  }

  function selectProduct(id: number) {
    const product = products.find((p) => p.id === id)
    if (!product) return
    // Épuisé → on ne le sélectionne pas (parité POS web) : mieux vaut refuser
    // franchement que laisser bâtir une ligne qui sera rejetée au push.
    const left = remaining(product, items)
    if (left !== null && left <= 0) {
      setSoldOut(product.name)
      return
    }
    setSoldOut(null)
    setProductId(String(id))
    setPriceTouched(false)
    setGross('')
    setTare('')
    // Clamp dès la sélection : 0,4 kg en stock → 0,4 proposé, jamais 1.
    setQuantity(left === null ? '1' : String(Math.min(1, left)))
  }

  function bumpQuantity(delta: number) {
    const next = roundQuantity(Math.max(0, qty + delta * step), unit)
    setQuantity(String(available !== null ? Math.min(next, available) : next))
  }

  /** Pesée façon balance : net = brut − tare, reporté en quantité. */
  function applyWeigh() {
    if (net <= 0) return
    setQuantity(String(available !== null ? Math.min(net, available) : net))
  }

  function addItem() {
    if (!selectedProduct || !canAdd) return
    setItems((current) => [
      ...current,
      {
        product_id: selectedProduct.id,
        product_name: selectedProduct.name,
        product_type: selectedProduct.product_type,
        unit: selectedProduct.unit ?? 'unité',
        quantity: roundQuantity(qty, selectedProduct.unit),
        unit_price: Number(unitPrice),
        max: selectedProduct.available_quantity ?? null,
      },
    ])
    resetLine()
  }

  const total = items.reduce((sum, item) => sum + item.quantity * item.unit_price, 0)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!clientId || items.length === 0) return

    const client = clients.find((c) => c.id === Number(clientId))
    await enqueue(
      'sale.create',
      {
        client_id: Number(clientId),
        sale_date: new Date().toISOString().slice(0, 10),
        type: 'bon_livraison',
        // `max` n'est qu'une aide de saisie locale : elle ne part pas au serveur.
        items: items.map(({ max: _max, ...line }) => line),
        immediate_payment: immediatePayment ? Number(immediatePayment) : null,
        payment_method: immediatePayment ? paymentMethod : null,
        notes: notes.trim() || null,
      },
      t('Vente :name (:count art.)', { name: client?.name ?? '', count: items.length }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Vente enregistrée (brouillon)')}</p>
        <p className="muted">{t('La validation et le déstockage se font au bureau, en ligne.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('💰 Vente rapide')}</h2>
      <Link to="/commerce/journal" className="section-link" style={{ display: 'inline-block', marginBottom: 8 }}>
        {t('Voir le journal du jour')} →
      </Link>

      <label htmlFor="client">{t('Client')}</label>
      <select id="client" required value={clientId} onChange={(e) => setClientId(e.target.value)}>
        <option value="" disabled>
          {t('— Choisir un client —')}
        </option>
        {clients.map((client) => (
          <option key={client.id} value={client.id}>
            {client.name}
          </option>
        ))}
      </select>
      {/* Le palier appliqué est affiché : vendre au détail à un grossiste doit
          se voir avant l'encaissement, pas dans le rapport de marge du mois. */}
      {book.listName && (
        <p className="muted">{t('Tarif appliqué : :name', { name: book.listName })}</p>
      )}

      {items.length > 0 && (
        <section>
          <h3>{t('Panier (:count)', { count: items.length })}</h3>
          {items.map((item, index) => (
            <div key={index} className="record-row">
              <span>
                {item.product_name} × {item.quantity.toLocaleString(dateLocale())} {item.unit}
              </span>
              <span className="task-meta">
                {Math.round(item.quantity * item.unit_price).toLocaleString(dateLocale())}
                <button
                  type="button"
                  className="row-delete"
                  aria-label={t('Retirer :name', { name: item.product_name })}
                  onClick={() => setItems((current) => current.filter((_, i) => i !== index))}
                >
                  ✕
                </button>
              </span>
            </div>
          ))}
          <p className="task-title">{t('Total : :amount', { amount: Math.round(total).toLocaleString(dateLocale()) })}</p>
        </section>
      )}

      <h3>{t('Ajouter un article')}</h3>
      {/* Pavé PLU / recherche : un code exact sélectionne directement l'article. */}
      <input
        id="plu"
        inputMode="search"
        value={search}
        onChange={(e) => {
          const value = e.target.value
          setSearch(value)
          const code = value.trim().toLowerCase()
          const exact = code ? products.find((p) => (p.sku ?? '').toLowerCase() === code) : undefined
          if (exact) selectProduct(exact.id)
        }}
        placeholder={t('Code PLU ou nom d’article…')}
        aria-label={t('Code PLU ou nom d’article')}
      />

      <select
        value={productId}
        onChange={(e) => (e.target.value ? selectProduct(Number(e.target.value)) : resetLine())}
        aria-label={t('Article')}
      >
        <option value="" disabled>
          {t('— Choisir un article —')}
        </option>
        {matches.map((product) => {
          const left = remaining(product, items)
          const out = left !== null && left <= 0
          return (
            <option key={product.id} value={product.id} disabled={out}>
              {product.is_favorite ? '★ ' : ''}{product.name}
              {product.sku ? ` [${product.sku}]` : ''}
              {out
                ? ` · ${t('épuisé')}`
                : ` · ${Math.round(priceFor(product, book)).toLocaleString(dateLocale())}/${product.unit ?? t('u')}`}
            </option>
          )
        })}
      </select>
      {search.trim() !== '' && matches.length === 0 && (
        <p className="muted">{t('Aucun article ne correspond à « :q ».', { q: search.trim() })}</p>
      )}
      {soldOut && (
        <p className="error">{t('⚠️ :name est épuisé — rien à vendre.', { name: soldOut })}</p>
      )}

      {selectedProduct && (
        <>
          <p className="proof-hint">
            {available === null
              ? t(':name — stock non suivi', { name: selectedProduct.name })
              : t(':name — :n :unit disponibles', {
                  name: selectedProduct.name, n: available.toLocaleString(dateLocale()), unit: selectedProduct.unit ?? '',
                })}
          </p>

          {weighable && (
            <>
              <label htmlFor="gross">{t('Pesée — brut − tare (:unit)', { unit: selectedProduct.unit ?? '' })}</label>
              <div className="chip-row">
                <input
                  id="gross"
                  type="number"
                  inputMode="decimal"
                  min={0}
                  step="0.005"
                  value={gross}
                  onChange={(e) => setGross(e.target.value)}
                  placeholder={t('Brut')}
                />
                <input
                  type="number"
                  inputMode="decimal"
                  min={0}
                  step="0.005"
                  value={tare}
                  onChange={(e) => setTare(e.target.value)}
                  placeholder={t('Tare (bac)')}
                  aria-label={t('Tare (bac)')}
                />
                <button type="button" className="chip" onClick={applyWeigh} disabled={net <= 0}>
                  = {net.toLocaleString(dateLocale())} {selectedProduct.unit}
                </button>
              </div>
            </>
          )}

          <label htmlFor="qty">
            {t('Quantité (:unit)', { unit: selectedProduct.unit ?? t('unité') })}
          </label>
          <div className="chip-row">
            <button type="button" className="chip" onClick={() => bumpQuantity(-1)} aria-label={t('Diminuer')}>
              −
            </button>
            <input
              id="qty"
              type="number"
              inputMode="decimal"
              min={0}
              step={weighable ? '0.001' : '1'}
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
            />
            <button type="button" className="chip" onClick={() => bumpQuantity(1)} aria-label={t('Augmenter')}>
              +
            </button>
          </div>
          {overStock && (
            <p className="error">
              {t('⚠️ Stock insuffisant : :n :unit disponibles seulement.', {
                n: (available ?? 0).toLocaleString(dateLocale()), unit: selectedProduct.unit ?? '',
              })}
            </p>
          )}
          {fractional && !overStock && (
            <p className="error">
              {t('⚠️ :unit se vend par unité entière — pas de décimale.', {
                unit: selectedProduct.unit ?? t('unité'),
              })}
            </p>
          )}

          <label htmlFor="unit_price">
            {t('Prix unitaire (par :unit)', { unit: selectedProduct.unit ?? t('unité') })}
          </label>
          <input
            id="unit_price"
            type="number"
            inputMode="numeric"
            min="0"
            value={unitPrice}
            onChange={(e) => { setPriceTouched(true); setUnitPrice(e.target.value) }}
          />
          {qty > 0 && Number(unitPrice) > 0 && (
            <p className="muted">
              {t('Ligne : :amount', {
                amount: Math.round(qty * Number(unitPrice)).toLocaleString(dateLocale()),
              })}
            </p>
          )}

          <button type="button" className="btn-secondary" onClick={addItem} disabled={!canAdd}>
            {t('+ Ajouter au panier')}
          </button>
        </>
      )}

      {items.length > 0 && (
        <>
          <label htmlFor="immediate_payment">{t('Acompte encaissé — optionnel')}</label>
          <input
            id="immediate_payment"
            type="number"
            inputMode="numeric"
            min="0"
            value={immediatePayment}
            onChange={(e) => setImmediatePayment(e.target.value)}
          />
          {Number(immediatePayment) > 0 && (
            <div className="chip-row">
              {([
                ['especes', `💵 ${t('Espèces')}`],
                ['mobile_money', `📱 ${t('Mobile Money')}`],
                ['virement', `🏦 ${t('Virement')}`],
                ['cheque', `🧾 ${t('Chèque')}`],
              ] as const).map(([value, lbl]) => (
                <button
                  key={value}
                  type="button"
                  className={`chip ${paymentMethod === value ? 'chip-on' : ''}`}
                  onClick={() => setPaymentMethod(value)}
                >
                  {lbl}
                </button>
              ))}
            </div>
          )}

          <label htmlFor="sale_notes">{t('Observations — optionnel')}</label>
          <textarea id="sale_notes" rows={2} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </>
      )}

      <button type="submit" className="btn-primary" disabled={!clientId || items.length === 0}>
        {t('Enregistrer la vente (brouillon)')}
      </button>
    </form>
  )
}
