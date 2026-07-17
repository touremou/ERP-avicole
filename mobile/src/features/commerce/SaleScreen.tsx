/**
 * Vente rapide — créée en BROUILLON (bon de livraison) : la validation et le
 * déstockage restent des opérations EN LIGNE (matrice de conflits, RFC §3.3).
 * Contrat : SyncService::saleCreate (gate commerce.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import { t, dateLocale } from '../../i18n'
import type { RefClient, RefProduct } from '../../api/types'

interface SaleItem {
  product_id: number
  product_name: string
  product_type: string
  unit: string
  quantity: number
  unit_price: number
}

export function SaleScreen() {
  const navigate = useNavigate()
  const [clients, setClients] = useState<RefClient[]>([])
  const [products, setProducts] = useState<RefProduct[]>([])
  const [clientId, setClientId] = useState('')
  const [items, setItems] = useState<SaleItem[]>([])
  const [productId, setProductId] = useState('')
  const [quantity, setQuantity] = useState(1)
  const [unitPrice, setUnitPrice] = useState('')
  const [immediatePayment, setImmediatePayment] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('especes')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_clients.orderBy('name').toArray().then(setClients)
    void db.ref_products.filter((p) => p.is_active).toArray().then(setProducts)
  }, [])

  const selectedProduct = useMemo(
    () => products.find((p) => p.id === Number(productId)),
    [products, productId],
  )

  // Pré-remplissage du prix depuis le catalogue (surchargeable).
  useEffect(() => {
    if (selectedProduct) setUnitPrice(String(selectedProduct.base_price))
  }, [selectedProduct])

  const total = items.reduce((sum, item) => sum + item.quantity * item.unit_price, 0)

  function addItem() {
    if (!selectedProduct || quantity <= 0 || !unitPrice) return
    setItems((current) => [
      ...current,
      {
        product_id: selectedProduct.id,
        product_name: selectedProduct.name,
        product_type: selectedProduct.product_type,
        unit: selectedProduct.unit ?? 'unité',
        quantity,
        unit_price: Number(unitPrice),
      },
    ])
    setProductId('')
    setQuantity(1)
    setUnitPrice('')
  }

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
        items,
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

      {items.length > 0 && (
        <section>
          <h3>{t('Panier (:count)', { count: items.length })}</h3>
          {items.map((item, index) => (
            <div key={index} className="record-row">
              <span>
                {item.product_name} × {item.quantity}
              </span>
              <span className="task-meta">
                {(item.quantity * item.unit_price).toLocaleString(dateLocale())}
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
          <p className="task-title">{t('Total : :amount', { amount: total.toLocaleString(dateLocale()) })}</p>
        </section>
      )}

      <h3>{t('Ajouter un article')}</h3>
      <select value={productId} onChange={(e) => setProductId(e.target.value)} aria-label={t('Article')}>
        <option value="" disabled>
          {t('— Choisir un article —')}
        </option>
        {products.map((product) => (
          <option key={product.id} value={product.id}>
            {product.name} ({product.base_price.toLocaleString(dateLocale())}/{product.unit ?? t('u')})
          </option>
        ))}
      </select>

      {selectedProduct && (
        <>
          <NumberStepper label={t('Quantité (:unit)', { unit: selectedProduct.unit ?? t('unité') })} value={quantity} onChange={setQuantity} min={1} />
          <label htmlFor="unit_price">{t('Prix unitaire')}</label>
          <input
            id="unit_price"
            type="number"
            inputMode="numeric"
            min="0"
            value={unitPrice}
            onChange={(e) => setUnitPrice(e.target.value)}
          />
          <button type="button" className="btn-secondary" onClick={addItem}>
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
