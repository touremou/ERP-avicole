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
import { dateLocale, t } from '../../i18n'
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

  // Détails optionnels (repliés) : parité web — pesée précise, pertes, stock.
  const [showDetails, setShowDetails] = useState(false)
  const [netWeightKg, setNetWeightKg] = useState('')
  const [lossQuantity, setLossQuantity] = useState('')
  const [syncToStock, setSyncToStock] = useState(false)
  const [stockItemName, setStockItemName] = useState('')

  useEffect(() => {
    if (cycleId) void db.ref_crop_cycles.get(Number(cycleId)).then((c) => setCycle(c ?? null))
  }, [cycleId])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!cycle || quantity <= 0) return

    const num = (v: string) => (v.trim() !== '' ? Number(v) : null)

    await enqueue(
      'harvest.create',
      {
        crop_cycle_id: cycle.id,
        harvest_date: new Date().toISOString().slice(0, 10),
        quantity,
        unit,
        quality,
        net_weight_kg: num(netWeightKg),
        loss_quantity: num(lossQuantity),
        sync_to_stock: syncToStock,
        stock_item_name: syncToStock && stockItemName.trim() ? stockItemName.trim() : null,
        notes: notes || null,
      },
      t('Récolte :crop — :code (:qty :unit)', { crop: cycle.crop_name, code: cycle.code, qty: quantity, unit }),
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
        <p className="success big">{t('✓ Récolte enregistrée')}</p>
        <p className="muted">{t('Le cycle passe en phase récolte côté serveur.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🌾 Récolte — :crop', { crop: cycle.crop_name })}</h2>
      <p className="muted">
        {cycle.code}
        {cycle.variety ? ` · ${cycle.variety}` : ''} · {new Date().toLocaleDateString(dateLocale())}
      </p>

      <NumberStepper label={t('Quantité récoltée (:unit)', { unit })} value={quantity} onChange={setQuantity} min={0} step={5} />

      <label>{t('Unité')}</label>
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
          {t("Sans pesée en kg, cette récolte n'alimentera pas les KPI de rendement — le poids net pourra être complété sur le web.")}
        </p>
      )}

      <label>{t('Qualité')}</label>
      <div className="chip-row">
        {QUALITIES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${quality === option.value ? 'chip-on' : ''}`}
            onClick={() => setQuality(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <button type="button" className="btn-secondary" onClick={() => setShowDetails((s) => !s)}>
        {showDetails ? t('▲ Masquer les détails') : t('▼ Plus de détails (optionnel)')}
      </button>

      {showDetails && (
        <>
          <label htmlFor="netw">{t('Poids net pesé (kg) — optionnel')}</label>
          <input id="netw" type="number" inputMode="decimal" min="0" value={netWeightKg} onChange={(e) => setNetWeightKg(e.target.value)} placeholder={t('pesée précise pour le rendement')} />

          <label htmlFor="loss">{t('Pertes / déchets (:unit)', { unit })}</label>
          <input id="loss" type="number" inputMode="decimal" min="0" value={lossQuantity} onChange={(e) => setLossQuantity(e.target.value)} placeholder="0" />

          <button
            type="button"
            className={`chip ${syncToStock ? 'chip-on' : ''}`}
            onClick={() => setSyncToStock((v) => !v)}
          >
            {syncToStock ? `✓ ${t('Versé au stock')}` : t('Verser cette récolte au stock ?')}
          </button>
          {syncToStock && (
            <>
              <label htmlFor="stockname">{t('Nom en stock — optionnel')}</label>
              <input id="stockname" maxLength={255} value={stockItemName} onChange={(e) => setStockItemName(e.target.value)} placeholder={cycle.crop_name} />
            </>
          )}
        </>
      )}

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={quantity <= 0}>
        {t('Enregistrer la récolte')}
      </button>

      <Link to={`/cultures/intrant/${cycle.id}`} className="btn-secondary" style={{ textAlign: 'center', lineHeight: '56px', textDecoration: 'none' }}>
        {t('🧪 Saisir un intrant plutôt')}
      </Link>
    </form>
  )
}
