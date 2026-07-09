/**
 * Récolte — saisie terrain sur un cycle de culture en cours. Le serveur
 * bascule le cycle en phase « recolte » à la première saisie et refuse un
 * cycle clos (conflict → bac « À corriger »). Contrat :
 * SyncService::harvestCreate (gate cultures.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefCropCycle } from '../../api/types'

const UNITS = ['kg', 'sac', 'botte', 'régime', 'unité'] as const
const QUALITIES = [
  { value: 'bon', label: '✅ Bon' },
  { value: 'moyen', label: '➖ Moyen' },
  { value: 'mediocre', label: '⚠️ Médiocre' },
] as const

export function HarvestScreen() {
  const { cycleId } = useParams()
  const navigate = useNavigate()

  const [cycle, setCycle] = useState<RefCropCycle | null>(null)
  const [quantity, setQuantity] = useState(0)
  const [unit, setUnit] = useState<string>('kg')
  const [quality, setQuality] = useState<string>('bon')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (cycleId) void db.ref_crop_cycles.get(Number(cycleId)).then((c) => setCycle(c ?? null))
  }, [cycleId])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!cycle || quantity <= 0) return

    await enqueue(
      'harvest.create',
      {
        crop_cycle_id: cycle.id,
        harvest_date: new Date().toISOString().slice(0, 10),
        quantity,
        unit,
        quality,
        notes: notes || null,
      },
      `Récolte ${cycle.crop_name} — ${cycle.code} (${quantity} ${unit})`,
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!cycle) {
    return (
      <div className="screen">
        <p className="muted">Cycle de culture introuvable en local — synchronisez d'abord.</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ Récolte enregistrée</p>
        <p className="muted">Le cycle passe en phase récolte côté serveur.</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🌾 Récolte — {cycle.crop_name}</h2>
      <p className="muted">
        {cycle.code}
        {cycle.variety ? ` · ${cycle.variety}` : ''} · {new Date().toLocaleDateString('fr-FR')}
      </p>

      <NumberStepper label={`Quantité récoltée (${unit})`} value={quantity} onChange={setQuantity} min={0} step={5} />

      <label>Unité</label>
      <div className="chip-row">
        {UNITS.map((option) => (
          <button
            key={option}
            type="button"
            className={`chip ${unit === option ? 'chip-on' : ''}`}
            onClick={() => setUnit(option)}
          >
            {option}
          </button>
        ))}
      </div>
      {unit !== 'kg' && (
        <p className="muted">
          Sans pesée en kg, cette récolte n'alimentera pas les KPI de rendement — le poids net
          pourra être complété sur le web.
        </p>
      )}

      <label>Qualité</label>
      <div className="chip-row">
        {QUALITIES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${quality === option.value ? 'chip-on' : ''}`}
            onClick={() => setQuality(option.value)}
          >
            {option.label}
          </button>
        ))}
      </div>

      <label htmlFor="notes">Observations — optionnel</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={quantity <= 0}>
        Enregistrer la récolte
      </button>

      <Link to={`/cultures/intrant/${cycle.id}`} className="btn-secondary" style={{ textAlign: 'center', lineHeight: '56px', textDecoration: 'none' }}>
        🧪 Saisir un intrant plutôt
      </Link>
    </form>
  )
}
