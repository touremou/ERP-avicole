/**
 * Retour client — reprise de marchandise CHEZ le client (invendu, non conforme).
 * On saisit ligne par ligne les quantités reprises ; le serveur remet en stock
 * et génère l'avoir via ProcessSaleReturn (source unique avec le web).
 * Contrat : SyncService::saleReturnCreate (gate commerce.M), idempotent par uuid.
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefClient, RefSale, RefSaleItem } from '../../api/types'

const METHODS = [
  { value: 'especes', label: '💵 Espèces' },
  { value: 'orange_money', label: '📱 Orange Money' },
  { value: 'virement', label: '🏦 Virement' },
  { value: 'cheque', label: '📝 Chèque' },
] as const

function money(value: number): string {
  return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Math.round(value || 0))
}

export function SaleReturnScreen() {
  const { saleId } = useParams()
  const navigate = useNavigate()

  const [sales, setSales] = useState<RefSale[]>([])
  const [clients, setClients] = useState<RefClient[]>([])
  const [items, setItems] = useState<RefSaleItem[]>([])
  const [selected, setSelected] = useState(saleId ?? '')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [method, setMethod] = useState<(typeof METHODS)[number]['value']>('especes')
  const [reason, setReason] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_sales.reverse().sortBy('sale_date').then((rows) => setSales(rows.slice(0, 60)))
    void db.ref_clients.toArray().then(setClients)
  }, [])

  // Lignes de la vente choisie (les quantités reprises se saisissent dessus).
  useEffect(() => {
    setQuantities({})
    if (!selected) { setItems([]); return }
    void db.ref_sale_items.where('sale_id').equals(Number(selected)).toArray().then(setItems)
  }, [selected])

  const clientName = (id: number | null) =>
    clients.find((c) => c.id === id)?.name ?? t('Client comptoir')

  const sale = sales.find((s) => s.id === Number(selected)) ?? null

  // Valeur de la marchandise reprise. ⚠️ Ce n'est PAS forcément le
  // remboursement : le serveur ne rend que le trop-perçu (déjà payé − nouveau
  // total). Sur une créance impayée, la reprise réduit la dette, sans espèces.
  const returnedValue = useMemo(
    () => items.reduce((sum, item) => {
      const qty = Number(quantities[item.id]) || 0
      return sum + qty * Number(item.unit_price)
    }, 0),
    [items, quantities],
  )

  // Une quantité reprise ne peut pas dépasser la quantité vendue.
  const over = items.some((item) => (Number(quantities[item.id]) || 0) > Number(item.quantity) + 0.001)
  const filled = items.filter((item) => (Number(quantities[item.id]) || 0) > 0)
  const canSubmit = Boolean(selected) && filled.length > 0 && !over

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit || !sale) return

    await enqueue(
      'sale_return.create',
      {
        sale_id: sale.id,
        reason: reason.trim() || null,
        refund_method: method,
        lines: filled.map((item) => ({
          sale_item_id: item.id,
          quantity: Number(quantities[item.id]),
        })),
      },
      t('Retour :ref — :amount', { ref: sale.reference, amount: money(returnedValue) }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Retour enregistré')}</p>
        <p className="muted">{t('Remise en stock et avoir seront générés par le serveur au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>↩️ {t('Retour client')}</h2>

      <label htmlFor="sale">{t('Vente concernée')}</label>
      <select id="sale" required value={selected} onChange={(e) => setSelected(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {sales.map((s) => (
          <option key={s.id} value={s.id}>
            {s.reference} · {clientName(s.client_id)} · {s.sale_date}
          </option>
        ))}
      </select>
      {sales.length === 0 && (
        <p className="muted">{t('Aucune vente en local — synchronisez d’abord.')}</p>
      )}

      {items.length > 0 && (
        <>
          <label>{t('Quantités reprises')}</label>
          {items.map((item) => (
            <div key={item.id} className="round-row">
              <div className="cut-line">
                <span className="cut-label">
                  {item.product_name}
                  <span className="muted"> · {t('vendu :qty :unit', { qty: item.quantity, unit: item.unit ?? '' })}</span>
                </span>
                <input
                  type="number"
                  inputMode="decimal"
                  min={0}
                  max={Number(item.quantity)}
                  step="0.01"
                  value={quantities[item.id] ?? ''}
                  onChange={(e) => setQuantities((prev) => ({ ...prev, [item.id]: e.target.value }))}
                  placeholder="0"
                  aria-label={item.product_name}
                />
              </div>
              {(Number(quantities[item.id]) || 0) > Number(item.quantity) + 0.001 && (
                <p className="error">{t('⚠️ Supérieur à la quantité vendue (:qty).', { qty: item.quantity })}</p>
              )}
            </div>
          ))}

          <p className="big">{t('Valeur reprise')} : {money(returnedValue)}</p>
          <p className="muted">{t('Le remboursement effectif = trop-perçu (déjà réglé − nouveau total), calculé au push.')}</p>
        </>
      )}

      {selected && items.length === 0 && (
        <p className="muted">{t('Lignes de cette vente absentes en local — synchronisez d’abord.')}</p>
      )}

      <label>{t('Mode de remboursement')}</label>
      <div className="chip-row">
        {METHODS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${method === option.value ? 'chip-on' : ''}`}
            onClick={() => setMethod(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label htmlFor="reason">{t('Motif du retour — optionnel')}</label>
      <textarea id="reason" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('ex. invendu, non conforme')} />

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer le retour')}
      </button>
    </form>
  )
}
