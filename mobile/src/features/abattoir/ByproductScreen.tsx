/**
 * Sous-produits d'abattage (E9) — sang, plumes, viscères : volume pesé et
 * destination tracés depuis le poste de collecte. Contrat :
 * SyncService::byproductCreate (gate abattoir.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefSlaughterOrder } from '../../api/types'

const TYPES = [
  { value: 'sang', label: '🩸 Sang' },
  { value: 'plumes', label: '🪶 Plumes' },
  { value: 'visceres', label: '🫀 Viscères' },
  { value: 'autre', label: '📦 Autre' },
] as const

const DESTINATIONS = [
  { value: 'equarrissage', label: 'Équarrissage' },
  { value: 'vente', label: 'Vente' },
  { value: 'compost', label: 'Compost' },
  { value: 'dechets', label: 'Déchets' },
  { value: 'autre', label: 'Autre' },
] as const

export function ByproductScreen() {
  const navigate = useNavigate()

  const [orders, setOrders] = useState<RefSlaughterOrder[]>([])
  const [orderId, setOrderId] = useState('')
  const [type, setType] = useState<string>('sang')
  const [quantity, setQuantity] = useState('')
  const [destination, setDestination] = useState<string>('equarrissage')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_slaughter_orders
      .where('status').anyOf('termine', 'en_cours', 'bloque')
      .reverse().sortBy('planned_date')
      .then((found) => setOrders(found.slice(0, 30)))
  }, [])

  const qty = Number(quantity)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (qty <= 0) return

    await enqueue(
      'byproduct.create',
      {
        slaughter_order_id: orderId ? Number(orderId) : null,
        type,
        quantity_kg: qty,
        destination,
        notes: notes || null,
        collected_at: new Date().toISOString(),
      },
      t('Sous-produit :type (:qty kg)', {
        type: TYPES.find((o) => o.value === type)?.label ?? type,
        qty,
      }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Sous-produit enregistré')}</p>
        <p className="muted">{t('Volume et destination tracés au registre.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>♻️ {t('Sous-produit d\'abattage')}</h2>

      <label>{t('Type')}</label>
      <div className="chip-row">
        {TYPES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${type === option.value ? 'chip-on' : ''}`}
            onClick={() => setType(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label htmlFor="qty">{t('Quantité (kg)')}</label>
      <input
        id="qty"
        type="number"
        inputMode="decimal"
        min={0.01}
        step="0.1"
        required
        value={quantity}
        onChange={(e) => setQuantity(e.target.value)}
        placeholder={t('ex. 12.5')}
      />

      <label>{t('Destination')}</label>
      <div className="chip-row">
        {DESTINATIONS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${destination === option.value ? 'chip-on' : ''}`}
            onClick={() => setDestination(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label htmlFor="order">{t('Ordre d\'abattage — optionnel')}</label>
      <select id="order" value={orderId} onChange={(e) => setOrderId(e.target.value)}>
        <option value="">{t('— Aucun —')}</option>
        {orders.map((order) => (
          <option key={order.id} value={order.id}>
            {order.order_number}
          </option>
        ))}
      </select>

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={qty <= 0}>
        {t('Enregistrer le sous-produit')}
      </button>
    </form>
  )
}
