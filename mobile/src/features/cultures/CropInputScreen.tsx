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
import { dateLocale, t } from '../../i18n'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefCropCycle, RefProvider } from '../../api/types'

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
  const [providers, setProviders] = useState<RefProvider[]>([])
  const [type, setType] = useState<string>('engrais')
  const [name, setName] = useState('')
  const [providerId, setProviderId] = useState('')
  const [quantity, setQuantity] = useState(0)
  const [unit, setUnit] = useState('kg')
  const [unitCost, setUnitCost] = useState(0)
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (cycleId) void db.ref_crop_cycles.get(Number(cycleId)).then((c) => setCycle(c ?? null))
    void db.ref_providers.orderBy('name').toArray().then(setProviders)
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
        provider_id: providerId ? Number(providerId) : null,
        quantity: quantity || null,
        unit: unit || null,
        unit_cost: unitCost || null,
        notes: notes || null,
      },
      t('Intrant :name — :code', { name: name.trim(), code: cycle.code }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!cycle) {
    return (
      <div className="screen">
        <p className="muted">{t("Cycle de culture introuvable en local — synchronisez d'abord.")}</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Intrant enregistré')}</p>
        <p className="muted">{t('Son coût alimentera la marge du cycle.')}</p>
      </div>
    )
  }

  const totalCost = Math.round(quantity * unitCost)

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🧪 Intrant — :crop', { crop: cycle.crop_name })}</h2>
      <p className="muted">
        {cycle.code} · {new Date().toLocaleDateString(dateLocale())}
      </p>

      <label>{t("Type d'intrant")}</label>
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

      <label htmlFor="name">{t('Désignation')}</label>
      <input
        id="name"
        required
        maxLength={255}
        value={name}
        onChange={(e) => setName(e.target.value)}
        placeholder={t('ex. Urée 46%')}
      />

      {providers.length > 0 && (
        <>
          <label htmlFor="provider">{t('Fournisseur — optionnel')}</label>
          <select id="provider" value={providerId} onChange={(e) => setProviderId(e.target.value)}>
            <option value="">{t('— Aucun —')}</option>
            {providers.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        </>
      )}

      <NumberStepper label={t('Quantité (:unit)', { unit: unit || '—' })} value={quantity} onChange={setQuantity} min={0} />

      <label htmlFor="unit">{t('Unité')}</label>
      <input id="unit" maxLength={20} value={unit} onChange={(e) => setUnit(e.target.value)} placeholder={t('kg, L, jour…')} />

      <NumberStepper label={t('Coût unitaire (GNF)')} value={unitCost} onChange={setUnitCost} min={0} step={500} />
      {totalCost > 0 && (
        <p className="muted">{t('Coût total dérivé : :amount GNF', { amount: totalCost.toLocaleString(dateLocale()) })}</p>
      )}

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!name.trim()}>
        {t("Enregistrer l'intrant")}
      </button>
    </form>
  )
}
