/**
 * Pointage des semis — déclaration terrain d'un NOUVEAU cycle de culture
 * (hors-ligne). On choisit une parcelle (miroir ref_plots), la culture, la
 * surface semée et la date. Le serveur vérifie la surface disponible (garde
 * anti-dépassement → conflict) et met la parcelle « en culture ». Contrat :
 * SyncService::cropCycleCreate (gate cultures.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { safeLoad } from '../../offline/safeLoad'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefPlot, RefCropCycle, RefCropSpecies } from '../../api/types'

const SEED_UNITS = ['kg', 'g', 'sac', 'plant', 'unité'] as const

export function SemisScreen() {
  const navigate = useNavigate()
  const [plots, setPlots] = useState<RefPlot[]>([])
  const [cycles, setCycles] = useState<RefCropCycle[]>([])
  const [species, setSpecies] = useState<RefCropSpecies[]>([])
  const [plotId, setPlotId] = useState('')
  const [cropName, setCropName] = useState('')
  const [variety, setVariety] = useState('')
  const [area, setArea] = useState('')
  const [seedQty, setSeedQty] = useState('')
  const [seedUnit, setSeedUnit] = useState<string>('kg')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void safeLoad('semis', async () => {
      setPlots(await db.ref_plots.where('status').notEqual('inactive').toArray())
      setCycles(await db.ref_crop_cycles.toArray())
      setSpecies(await db.ref_crop_species.orderBy('name').toArray())
    })
  }, [])

  // Surface DISPONIBLE de la parcelle choisie = surface totale − surfaces des
  // cycles en cours connus en local (garde optimiste, revérifiée au serveur).
  const remaining = useMemo(() => {
    const plot = plots.find((p) => p.id === Number(plotId))
    if (!plot) return null
    const total = Number(plot.area_ha ?? 0)
    const used = cycles
      .filter((c) => c.plot_id === plot.id && (c.status === 'en_cours' || c.status === 'recolte'))
      .reduce((sum, c) => sum + Number(c.area_used_ha ?? 0), 0)
    return Math.max(0, total - used)
  }, [plots, cycles, plotId])

  const areaNum = Number(area)
  const overCapacity = remaining !== null && areaNum > remaining + 0.0001
  const canSubmit =
    Boolean(plotId) && cropName.trim() !== '' && areaNum > 0 && !overCapacity

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    const plot = plots.find((p) => p.id === Number(plotId))
    await enqueue(
      'crop_cycle.create',
      {
        plot_id: Number(plotId),
        crop_name: cropName.trim(),
        variety: variety.trim() || null,
        area_used_ha: areaNum,
        planting_date: new Date().toISOString().slice(0, 10),
        seed_quantity: seedQty.trim() !== '' ? Number(seedQty) : null,
        seed_unit: seedQty.trim() !== '' ? seedUnit : null,
        notes: notes.trim() || null,
      },
      t('Semis :crop — :plot (:area ha)', { crop: cropName.trim(), plot: plot?.name ?? plotId, area: areaNum }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Semis enregistré')}</p>
        <p className="muted">{t('La parcelle passe « en culture » côté serveur.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🌱 Pointage des semis')}</h2>

      <label htmlFor="plot">{t('Parcelle')}</label>
      <select id="plot" required value={plotId} onChange={(e) => setPlotId(e.target.value)}>
        <option value="" disabled>{t('— Choisir une parcelle —')}</option>
        {plots.map((plot) => (
          <option key={plot.id} value={plot.id}>
            {plot.name} ({plot.code})
          </option>
        ))}
      </select>
      {plots.length === 0 && <p className="muted">{t("Aucune parcelle locale — synchronisez d'abord.")}</p>}
      {remaining !== null && (
        <p className="muted">{t('Surface disponible : :rem ha', { rem: remaining.toFixed(2) })}</p>
      )}

      <label htmlFor="crop">{t('Culture semée')}</label>
      {/* Liste du catalogue agronomique (miroir ref_crop_species) — parité avec
          le datalist du formulaire web ; saisie libre restant possible. */}
      <input id="crop" type="text" required maxLength={255} list="crop-species-list" value={cropName} onChange={(e) => setCropName(e.target.value)} placeholder={t('Ex. Tomate, Maïs, Oignon')} />
      <datalist id="crop-species-list">
        {species.map((s) => (
          <option key={s.id} value={s.name}>{s.local_name ? `${s.name} (${s.local_name})` : s.name}</option>
        ))}
      </datalist>

      <label htmlFor="variety">{t('Variété — optionnel')}</label>
      <input id="variety" type="text" maxLength={255} value={variety} onChange={(e) => setVariety(e.target.value)} placeholder={t('Ex. Roma, Local')} />

      <label htmlFor="area">{t('Surface semée (ha)')}</label>
      <input id="area" type="number" inputMode="decimal" min="0.001" step="0.01" required value={area} onFocus={(e) => e.target.select()} onChange={(e) => setArea(e.target.value)} placeholder={t('ex. 0.5')} />
      {overCapacity && (
        <p className="error">{t('⚠️ La surface dépasse le disponible (:rem ha) sur cette parcelle.', { rem: (remaining ?? 0).toFixed(2) })}</p>
      )}

      <label htmlFor="seed">{t('Quantité de semence — optionnel')}</label>
      <input id="seed" type="number" inputMode="decimal" min="0" step="any" value={seedQty} onFocus={(e) => e.target.select()} onChange={(e) => setSeedQty(e.target.value)} placeholder="0" />
      {seedQty.trim() !== '' && (
        <div className="chip-row">
          {SEED_UNITS.map((u) => (
            <button key={u} type="button" className={`chip ${seedUnit === u ? 'chip-on' : ''}`} onClick={() => setSeedUnit(u)}>
              {u}
            </button>
          ))}
        </div>
      )}

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer le semis')}
      </button>
    </form>
  )
}
