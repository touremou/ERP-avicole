/**
 * Exécution d'abattage — l'ordre est planifié au bureau (web), le terrain
 * saisit les quantités et pesées réelles. Les gardes métier (quarantaine,
 * effectif, statut sous verrou) sont rejouées par le serveur au push :
 * un refus sort vers le bac « À corriger ». Contrat :
 * SyncService::slaughterExecute (gate abattoir.M).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefBatch, RefSlaughterOrder } from '../../api/types'

export function SlaughterScreen() {
  const { orderId } = useParams()
  const navigate = useNavigate()

  const [order, setOrder] = useState<RefSlaughterOrder | null>(null)
  const [batch, setBatch] = useState<RefBatch | null>(null)
  const [actualQuantity, setActualQuantity] = useState(0)
  const [liveWeight, setLiveWeight] = useState('')
  const [carcassWeight, setCarcassWeight] = useState('')
  const [condemned, setCondemned] = useState(0)
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (!orderId) return
    void db.ref_slaughter_orders.get(Number(orderId)).then(async (found) => {
      setOrder(found ?? null)
      setActualQuantity(found?.planned_quantity ?? 0)
      if (found?.batch_id) setBatch((await db.ref_batches.get(found.batch_id)) ?? null)
    })
  }, [orderId])

  const live = Number(liveWeight)
  const carcass = Number(carcassWeight)
  const weightsValid = live > 0 && carcass > 0 && carcass <= live
  const yieldPercent = weightsValid ? Math.round((carcass / live) * 1000) / 10 : null

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!order || actualQuantity <= 0 || !weightsValid) return

    await enqueue(
      'slaughter.execute',
      {
        slaughter_order_id: order.id,
        execution_date: new Date().toISOString().slice(0, 10),
        actual_quantity: actualQuantity,
        total_live_weight_kg: live,
        total_carcass_weight_kg: carcass,
        condemned_count: condemned || null,
        inspector_notes: notes || null,
      },
      `Abattage ${order.order_number} (${actualQuantity} sujets)`,
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!order) {
    return (
      <div className="screen">
        <p className="muted">Ordre d'abattage introuvable en local — synchronisez d'abord.</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ Abattage enregistré</p>
        <p className="muted">Quarantaine et effectif seront re-vérifiés par le serveur au push.</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🔪 Abattage — {order.order_number}</h2>
      <p className="muted">
        {batch ? `Lot ${batch.code} · ` : ''}
        {order.planned_quantity} sujets planifiés · {new Date().toLocaleDateString('fr-FR')}
      </p>

      <NumberStepper label="Sujets abattus" value={actualQuantity} onChange={setActualQuantity} min={0} />
      {batch && actualQuantity > batch.current_quantity && (
        <p className="error">
          ⚠️ Supérieur à l'effectif local connu ({batch.current_quantity}) — le serveur tranchera au push.
        </p>
      )}

      <label htmlFor="live">Poids vif total (kg)</label>
      <input
        id="live"
        type="number"
        inputMode="decimal"
        min={0.1}
        step="0.1"
        required
        value={liveWeight}
        onChange={(e) => setLiveWeight(e.target.value)}
        placeholder="ex. 120.5"
      />

      <label htmlFor="carcass">Poids carcasse total (kg)</label>
      <input
        id="carcass"
        type="number"
        inputMode="decimal"
        min={0.1}
        step="0.1"
        required
        value={carcassWeight}
        onChange={(e) => setCarcassWeight(e.target.value)}
        placeholder="ex. 90.0"
      />
      {live > 0 && carcass > live && (
        <p className="error">⚠️ La carcasse ne peut pas peser plus que le vif.</p>
      )}
      {yieldPercent !== null && <p className="muted">Rendement carcasse : {yieldPercent} %</p>}

      <NumberStepper label="Saisies / condamnés" value={condemned} onChange={setCondemned} min={0} />

      <label htmlFor="notes">Notes d'inspection — optionnel</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={actualQuantity <= 0 || !weightsValid}>
        Enregistrer l'abattage
      </button>
    </form>
  )
}
