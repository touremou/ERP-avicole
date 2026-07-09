/**
 * Intrant de culture — saisie terrain itémisée (engrais, phyto, main
 * d'œuvre…) sur un cycle en cours. Le coût total est dérivé côté serveur
 * (quantité × coût unitaire). Contrat : SyncService::cropInputCreate
 * (gate cultures.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefCropCycle } from '../../api/types'

/** Miroir de CropInput::TYPES (référentiel serveur). */
const TYPES = [
  { value: 'semence', label: '🌱 Semence' },
  { value: 'engrais', label: '🧪 Engrais' },
  { value: 'phyto', label: '🛡️ Phyto' },
  { value: 'irrigation', label: '💧 Irrigation' },
  { value: 'main_doeuvre', label: "👷 Main d'œuvre" },
  { value: 'carburant', label: '⛽ Carburant' },
  { value: 'autre', label: '📦 Autre' },
] as const

export function CropInputScreen() {
  const { cycleId } = useParams()
  const navigate = useNavigate()

  const [cycle, setCycle] = useState<RefCropCycle | null>(null)
  const [type, setType] = useState<string>('engrais')
  const [name, setName] = useState('')
  const [quantity, setQuantity] = useState(0)
  const [unit, setUnit] = useState('kg')
  const [unitCost, setUnitCost] = useState(0)
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (cycleId) void db.ref_crop_cycles.get(Number(cycleId)).then((c) => setCycle(c ?? null))
  }, [cycleId])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!cycle || !name.trim()) return

    await enqueue(
      'crop_input.create',
      {
        crop_cycle_id: cycle.id,
        type,
        name: name.trim(),
        input_date: new Date().toISOString().slice(0, 10),
        quantity: quantity || null,
        unit: unit || null,
        unit_cost: unitCost || null,
        notes: notes || null,
      },
      `Intrant ${name.trim()} — ${cycle.code}`,
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
        <p className="success big">✓ Intrant enregistré</p>
        <p className="muted">Son coût alimentera la marge du cycle.</p>
      </div>
    )
  }

  const totalCost = Math.round(quantity * unitCost)

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🧪 Intrant — {cycle.crop_name}</h2>
      <p className="muted">
        {cycle.code} · {new Date().toLocaleDateString('fr-FR')}
      </p>

      <label>Type d'intrant</label>
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

      <label htmlFor="name">Désignation</label>
      <input
        id="name"
        required
        maxLength={255}
        value={name}
        onChange={(e) => setName(e.target.value)}
        placeholder="ex. Urée 46%"
      />

      <NumberStepper label={`Quantité (${unit || '—'})`} value={quantity} onChange={setQuantity} min={0} />

      <label htmlFor="unit">Unité</label>
      <input id="unit" maxLength={20} value={unit} onChange={(e) => setUnit(e.target.value)} placeholder="kg, L, jour…" />

      <NumberStepper label="Coût unitaire (GNF)" value={unitCost} onChange={setUnitCost} min={0} step={500} />
      {totalCost > 0 && (
        <p className="muted">Coût total dérivé : {totalCost.toLocaleString('fr-FR')} GNF</p>
      )}

      <label htmlFor="notes">Observations — optionnel</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!name.trim()}>
        Enregistrer l'intrant
      </button>
    </form>
  )
}
