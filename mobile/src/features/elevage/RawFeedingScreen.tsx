/**
 * NOURRIR (BRUT) — alimentation brute / spontanée : herbe coupée, fanes,
 * épluchures, feuillages. Contrat : SyncService::rawFeedingCreate.
 *
 * Demande de l'exploitation : le nourrissage imposait un aliment du stock,
 * issu d'une formule. Ici, ni formule ni stock : l'ouvrier dit QUOI il a
 * donné (liste ouverte), combien à peu près (« 3 brouettes ») et COMBIEN DE
 * TEMPS il y a passé. Ce temps, valorisé au coût horaire de l'employé, est
 * imputé au lot côté serveur : le vrai coût d'un mouton local nourri à l'herbe.
 *
 * Bonus : « herbe coupée sur la parcelle X » trace aussi l'entretien des
 * espaces verts de la ferme.
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { declaredNow, enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { t } from '../../i18n'
import type { RefBatch, RefPlot } from '../../api/types'
import { estLotReel } from '../../offline/virtuels'

/** Ressources proposées — la saisie reste libre (miroir de RawFeeding::RESSOURCES_COURANTES). */
const RESSOURCES = ['Herbe locale', 'Fanes de maïs', 'Fanes d’arachide', 'Feuillages', 'Épluchures', 'Restes de cuisine', 'Son de riz', 'Paille']

/** Unités d'estimation sans balance (miroir de RawFeeding::UNITES). */
const UNITES = ['kg', 'brouette', 'botte', 'sac', 'seau', 'fagot', 'tas']

/** Boutons rapides du temps passé, en minutes. */
const DUREES = [15, 30, 60, 120]

export function RawFeedingScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batches, setBatches] = useState<RefBatch[]>([])
  const [plots, setPlots] = useState<RefPlot[]>([])
  const [selected, setSelected] = useState(batchId ?? '')
  const [resource, setResource] = useState('')
  const [quantity, setQuantity] = useState('')
  const [unit, setUnit] = useState('brouette')
  const [minutes, setMinutes] = useState('')
  const [fromPlot, setFromPlot] = useState(false)
  const [plotId, setPlotId] = useState('')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_batches.where('status').equals('Actif').toArray().then((l) => setBatches(l.filter(estLotReel)))
    void db.ref_plots.toArray().then(setPlots)
  }, [])

  // Anti-corvée : la même ressource, la même unité qu'à la dernière distribution.
  useEffect(() => {
    void lastPayloadOf('raw_feeding.create', () => true).then((last) => {
      if (!last) return
      if (last.resource) setResource(String(last.resource))
      if (last.quantity_unit) setUnit(String(last.quantity_unit))
    })
  }, [])

  const minutesNum = Number(minutes)
  const minutesValid = Number.isInteger(minutesNum) && minutesNum >= 1 && minutesNum <= 720
  const canSubmit = Boolean(selected) && resource.trim() !== '' && minutesValid && (!fromPlot || plotId !== '')

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    const batch = batches.find((b) => b.id === Number(selected))
    await enqueue(
      'raw_feeding.create',
      {
        uuid: crypto.randomUUID(),
        batch_id: Number(selected),
        // Horodatage DÉCLARÉ : l'action compte au moment où elle a été faite.
        fed_at: declaredNow(),
        resource: resource.trim(),
        quantity: quantity.trim() === '' ? null : Number(quantity),
        quantity_unit: quantity.trim() === '' ? null : unit,
        minutes_spent: minutesNum,
        plot_id: fromPlot && plotId ? Number(plotId) : null,
        notes: notes.trim() || null,
      },
      t('Nourri (brut) :batch — :resource', { batch: batch?.code ?? selected, resource: resource.trim() }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Distribution enregistrée')}</p>
        <p className="muted">{t('Le temps passé sera imputé au lot à la synchronisation.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🌿 {t('Nourrir (brut)')}</h2>
      <p className="muted">{t('Herbe, fanes, épluchures… sans formule ni stock : on note ce qui a été donné et le temps passé.')}</p>

      <label htmlFor="batch">{t('Lot nourri')}</label>
      <select id="batch" required value={selected} onChange={(e) => setSelected(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {batches.map((b) => (
          <option key={b.id} value={b.id}>{b.code} · {b.current_quantity} {t('têtes')}</option>
        ))}
      </select>
      {batches.length === 0 && (
        <p className="muted">{t('Aucun lot actif en local — synchronisez d’abord.')}</p>
      )}

      <label htmlFor="resource">{t('Ressource donnée')}</label>
      <input
        id="resource"
        list="raw-feed-resources"
        required
        maxLength={120}
        value={resource}
        onChange={(e) => setResource(e.target.value)}
        placeholder={t('Herbe locale')}
      />
      <datalist id="raw-feed-resources">
        {RESSOURCES.map((r) => <option key={r} value={t(r)} />)}
      </datalist>
      <div className="chip-row">
        {RESSOURCES.slice(0, 4).map((r) => (
          <button type="button" key={r} className={`chip ${resource === t(r) ? 'chip-on' : ''}`} onClick={() => setResource(t(r))}>
            {t(r)}
          </button>
        ))}
      </div>

      <label htmlFor="quantity">{t('Quantité estimée — optionnel')}</label>
      <div className="chip-row">
        <input
          id="quantity"
          type="number"
          inputMode="decimal"
          min={0}
          step="0.5"
          value={quantity}
          onChange={(e) => setQuantity(e.target.value)}
          placeholder="3"
          style={{ flex: '1 1 90px' }}
        />
        <select aria-label={t('Unité')} value={unit} onChange={(e) => setUnit(e.target.value)} style={{ flex: '1 1 120px' }}>
          {UNITES.map((u) => <option key={u} value={u}>{t(u)}</option>)}
        </select>
      </div>

      <label htmlFor="minutes">{t('Temps passé (minutes)')}</label>
      <div className="chip-row">
        {DUREES.map((d) => (
          <button type="button" key={d} className={`chip ${minutesNum === d ? 'chip-on' : ''}`} onClick={() => setMinutes(String(d))}>
            {d < 60 ? t(':n min', { n: d }) : t(':n h', { n: d / 60 })}
          </button>
        ))}
      </div>
      <input
        id="minutes"
        type="number"
        inputMode="numeric"
        min={1}
        max={720}
        step={1}
        required
        value={minutes}
        onChange={(e) => setMinutes(e.target.value)}
        placeholder="45"
      />

      {plots.length > 0 && (
        <>
          <label className="chk-row">
            <input type="checkbox" checked={fromPlot} onChange={(e) => setFromPlot(e.target.checked)} />
            <span>{t('Coupé sur une parcelle de la ferme')}</span>
          </label>
          {fromPlot && (
            <select aria-label={t('Parcelle')} required value={plotId} onChange={(e) => setPlotId(e.target.value)}>
              <option value="">{t('Sélectionner…')}</option>
              {plots.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}
        </>
      )}

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer la distribution')}
      </button>
    </form>
  )
}
