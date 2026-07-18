/**
 * Mouvement de stock — entrée / sortie / ajustement sur un article du
 * miroir local. La disponibilité est re-vérifiée par le serveur au push
 * (sortie sur stock insuffisant → conflict → bac « À corriger »).
 * Contrat : SyncService::stockMovementCreate (gate logistique.M).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import { t } from '../../i18n'
import type { RefStock } from '../../api/types'

const TYPES = [
  { value: 'in', label: '⬇️ Entrée' },
  { value: 'out', label: '⬆️ Sortie' },
  { value: 'adjustment', label: '⚖️ Ajustement' },
] as const

export function StockMovementScreen() {
  const navigate = useNavigate()
  const [stocks, setStocks] = useState<RefStock[]>([])
  const [stockId, setStockId] = useState('')
  const [type, setType] = useState<'in' | 'out' | 'adjustment'>('out')
  const [quantity, setQuantity] = useState(0)
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    // item_name n'est pas un index de ref_stocks (id, category) : on trie en JS,
    // sinon Dexie orderBy('item_name') jette et la liste des articles reste vide.
    void db.ref_stocks.toArray().then((all) =>
      setStocks(all.sort((a, b) => a.item_name.localeCompare(b.item_name))),
    )
  }, [])

  const selected = useMemo(() => stocks.find((s) => s.id === Number(stockId)), [stocks, stockId])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!selected || quantity <= 0) return

    await enqueue(
      'stock_movement.create',
      {
        stock_id: selected.id,
        type,
        quantity,
        notes: notes || null,
      },
      `${t(TYPES.find((o) => o.value === type)?.label ?? type)} ${selected.item_name} (${quantity} ${selected.unit})`,
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Mouvement enregistré')}</p>
        <p className="muted">{t('La disponibilité sera re-vérifiée par le serveur au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('📦 Mouvement de stock')}</h2>
      <Link to="/logistique/stocks" className="section-link" style={{ display: 'inline-block', marginBottom: 8 }}>
        {t('Voir l’état des stocks')} →
      </Link>

      <label htmlFor="stock">{t('Article')}</label>
      <select id="stock" required value={stockId} onChange={(e) => setStockId(e.target.value)}>
        <option value="" disabled>
          {t('— Choisir un article —')}
        </option>
        {stocks.map((stock) => (
          <option key={stock.id} value={stock.id}>
            {stock.item_name} ({stock.current_quantity} {stock.unit})
          </option>
        ))}
      </select>

      <label>{t('Type de mouvement')}</label>
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

      <NumberStepper
        label={selected ? t('Quantité (:unit)', { unit: selected.unit }) : t('Quantité')}
        value={quantity}
        onChange={setQuantity}
        min={0}
        step={1}
      />
      {selected && type === 'out' && quantity > selected.current_quantity && (
        <p className="error">
          {t('⚠️ Supérieur au stock local connu (:quantity :unit) — le serveur tranchera au push.', {
            quantity: selected.current_quantity,
            unit: selected.unit,
          })}
        </p>
      )}

      <label htmlFor="notes">{t('Motif / destination — optionnel')}</label>
      <input id="notes" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={t('ex. Poulailler A')} />

      <button type="submit" className="btn-primary" disabled={!selected || quantity <= 0}>
        {t('Enregistrer le mouvement')}
      </button>
    </form>
  )
}
