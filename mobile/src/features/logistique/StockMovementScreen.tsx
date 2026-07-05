/**
 * Mouvement de stock — entrée / sortie / ajustement sur un article du
 * miroir local. La disponibilité est re-vérifiée par le serveur au push
 * (sortie sur stock insuffisant → conflict → bac « À corriger »).
 * Contrat : SyncService::stockMovementCreate (gate logistique.M).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
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
    void db.ref_stocks.orderBy('item_name').toArray().then(setStocks)
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
      `${TYPES.find((t) => t.value === type)?.label ?? type} ${selected.item_name} (${quantity} ${selected.unit})`,
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ Mouvement enregistré</p>
        <p className="muted">La disponibilité sera re-vérifiée par le serveur au push.</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>📦 Mouvement de stock</h2>

      <label htmlFor="stock">Article</label>
      <select id="stock" required value={stockId} onChange={(e) => setStockId(e.target.value)}>
        <option value="" disabled>
          — Choisir un article —
        </option>
        {stocks.map((stock) => (
          <option key={stock.id} value={stock.id}>
            {stock.item_name} ({stock.current_quantity} {stock.unit})
          </option>
        ))}
      </select>

      <label>Type de mouvement</label>
      <div className="chip-row">
        {TYPES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${type === option.value ? 'chip-on' : ''}`}
            onClick={() => setType(option.value)}
          >
            {option.label}
          </button>
        ))}
      </div>

      <NumberStepper
        label={`Quantité${selected ? ` (${selected.unit})` : ''}`}
        value={quantity}
        onChange={setQuantity}
        min={0}
        step={1}
      />
      {selected && type === 'out' && quantity > selected.current_quantity && (
        <p className="error">
          ⚠️ Supérieur au stock local connu ({selected.current_quantity} {selected.unit}) — le
          serveur tranchera au push.
        </p>
      )}

      <label htmlFor="notes">Motif / destination — optionnel</label>
      <input id="notes" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="ex. Poulailler A" />

      <button type="submit" className="btn-primary" disabled={!selected || quantity <= 0}>
        Enregistrer le mouvement
      </button>
    </form>
  )
}
