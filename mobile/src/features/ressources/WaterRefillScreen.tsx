/**
 * Ravitaillement d'une citerne (Ressources) — appoint d'eau saisi hors-ligne.
 * On choisit une citerne (miroir local), le volume ajouté, la date, un coût et
 * une note optionnels ; l'op water_refill.create est mise en file. Le niveau
 * est recalculé côté serveur au push (comme le ravitaillement web).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { safeLoad } from '../../offline/safeLoad'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import { t } from '../../i18n'
import type { RefWaterSource } from '../../api/types'

function levelPct(source: RefWaterSource): number {
  return Math.round(Number(source.current_level_percent ?? 0))
}

export function WaterRefillScreen() {
  const navigate = useNavigate()
  const [sources, setSources] = useState<RefWaterSource[]>([])
  const [sourceId, setSourceId] = useState('')
  const [volume, setVolume] = useState(0)
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [cost, setCost] = useState(0)
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void safeLoad('ravitaillement:citernes', async () => {
      const all = await db.ref_water_sources.toArray()
      setSources(
        all
          .filter((s) => s.type === 'citerne' && s.is_active)
          .sort((a, b) => a.name.localeCompare(b.name)),
      )
    })
  }, [])

  const selected = useMemo(() => sources.find((s) => s.id === Number(sourceId)), [sources, sourceId])
  const today = new Date().toISOString().slice(0, 10)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!selected || volume <= 0) return

    await enqueue(
      'water_refill.create',
      {
        water_source_id: selected.id,
        volume_added_liters: volume,
        refill_date: date,
        cost: cost > 0 ? cost : null,
        notes: notes || null,
      },
      `💧 ${t('Ravitaillement')} ${selected.name} (${volume} L)`,
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">💧 {t('Ravitaillement enregistré')}</p>
        <p className="muted">{t('Le niveau sera mis à jour côté serveur au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>💧 {t('Ravitaillement citerne')}</h2>

      {sources.length === 0 ? (
        <div className="ok-card ok-muted">
          {t('Aucune citerne — la synchronisation les rapatriera au premier passage réseau.')}
        </div>
      ) : (
        <>
          <label htmlFor="rf-source">{t('Citerne')}</label>
          <select id="rf-source" required value={sourceId} onChange={(e) => setSourceId(e.target.value)}>
            <option value="" disabled>{t('— Choisir une citerne —')}</option>
            {sources.map((s) => (
              <option key={s.id} value={s.id}>{s.name} ({levelPct(s)}%)</option>
            ))}
          </select>

          <NumberStepper label={t('Volume ajouté (L)')} value={volume} onChange={setVolume} min={0} step={100} />

          <label htmlFor="rf-date">{t('Date')}</label>
          <input id="rf-date" type="date" required value={date} max={today} onChange={(e) => setDate(e.target.value)} />

          <NumberStepper label={t('Coût (optionnel)')} value={cost} onChange={setCost} min={0} step={1000} />

          <label htmlFor="rf-notes">{t('Note — optionnel')}</label>
          <input id="rf-notes" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={t('Camion-citerne, fournisseur…')} />

          <button type="submit" className="btn-primary" disabled={!selected || volume <= 0}>
            {t('Enregistrer le ravitaillement')}
          </button>
        </>
      )}
    </form>
  )
}
