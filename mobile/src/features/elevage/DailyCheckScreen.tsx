/**
 * Pointage journalier — LA balle traçante de la Phase 0 : saisie 2-3 taps,
 * debout, hors-ligne. Steppers plutôt que clavier, confirmation instantanée
 * (optimistic), la sync part en arrière-plan.
 *
 * Payload aligné sur SyncService::dailyCheckCreate (validation serveur).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefBatch } from '../../api/types'

export function DailyCheckScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batch, setBatch] = useState<RefBatch | null>(null)
  const [mortality, setMortality] = useState(0)
  const [feedConsumed, setFeedConsumed] = useState(0)
  const [avgWeight, setAvgWeight] = useState('')
  const [observations, setObservations] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (batchId) void db.ref_batches.get(Number(batchId)).then((b) => setBatch(b ?? null))
  }, [batchId])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!batch) return

    await enqueue(
      'daily_check.create',
      {
        batch_id: batch.id,
        check_date: new Date().toISOString().slice(0, 10),
        mortality,
        feed_consumed: feedConsumed || null,
        avg_weight: avgWeight ? Number(avgWeight) : null,
        observations: observations || null,
      },
      `Pointage ${batch.code}`,
    )

    // Confirmation instantanée (règle UX n°8 : jamais de spinner bloquant).
    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!batch) {
    return (
      <div className="screen">
        <p className="muted">Lot introuvable en local — synchronisez d'abord.</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ Pointage enregistré</p>
        <p className="muted">Il partira au serveur dès que le réseau le permet.</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>Pointage — {batch.code}</h2>
      <p className="muted">
        {batch.current_quantity} sujets · {new Date().toLocaleDateString('fr-FR')}
      </p>

      <NumberStepper label="Mortalité (sujets)" value={mortality} onChange={setMortality} min={0} />

      <NumberStepper
        label="Aliment consommé (kg)"
        value={feedConsumed}
        onChange={setFeedConsumed}
        min={0}
        step={5}
      />

      <label htmlFor="avg_weight">Poids moyen (kg) — optionnel</label>
      <input
        id="avg_weight"
        type="number"
        inputMode="decimal"
        step="0.01"
        min="0"
        value={avgWeight}
        onChange={(e) => setAvgWeight(e.target.value)}
        placeholder="ex. 1.25"
      />

      <label htmlFor="observations">Observations — optionnel</label>
      <textarea
        id="observations"
        rows={2}
        maxLength={1000}
        value={observations}
        onChange={(e) => setObservations(e.target.value)}
        placeholder="Comportement, litière…"
      />

      <button type="submit" className="btn-primary">
        Enregistrer le pointage
      </button>
    </form>
  )
}
