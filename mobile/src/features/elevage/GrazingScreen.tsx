/**
 * SORTIE AU PÂTURAGE — ruminants dehors, gardés ou en autonomie.
 * Contrat : SyncService::grazingCreate → RecordGrazing (même action qu'au bureau).
 *
 * Demande de l'exploitation : « parfois, on laisse les ruminants à l'air
 * libre pour qu'ils mangent en autonomie ; comment tracer cette activité ? »
 * On note au RETOUR : où, de quelle heure à quelle heure, gardés ou non, et
 * surtout COMBIEN sont partis et COMBIEN sont rentrés. Une tête manquante
 * alerte l'élevage (pas de mortalité présumée). Le temps du berger est imputé
 * au lot côté serveur.
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { t } from '../../i18n'
import type { RefBatch, RefPlot } from '../../api/types'
import { estLotReel } from '../../offline/virtuels'
import { sortAuPaturage } from '../../offline/eligibilite'

/** Temps du berger par défaut en autonomie (RecordGrazing::MINUTES_AUTONOMIE_PAR_DEFAUT). */
const MINUTES_AUTONOMIE = 30

function aujourdhui(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function GrazingScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batches, setBatches] = useState<RefBatch[]>([])
  const [plots, setPlots] = useState<RefPlot[]>([])
  const [selected, setSelected] = useState(batchId ?? '')
  const [depart, setDepart] = useState('08:00')
  const [retour, setRetour] = useState(() => new Date().toTimeString().slice(0, 5))
  const [mode, setMode] = useState<'autonomie' | 'garde'>('autonomie')
  const [herderMinutes, setHerderMinutes] = useState(String(MINUTES_AUTONOMIE))
  const [sorties, setSorties] = useState('')
  const [rentrees, setRentrees] = useState('')
  const [plotId, setPlotId] = useState('')
  const [zone, setZone] = useState('')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState<number | null>(null)

  useEffect(() => {
    void db.ref_batches.where('status').equals('Actif').toArray()
      .then((l) => setBatches(l.filter((b) => estLotReel(b) && sortAuPaturage(b))))
    void db.ref_plots.toArray().then(setPlots)
  }, [])

  // Anti-corvée : le même mode, le même lieu qu'à la dernière sortie.
  useEffect(() => {
    void lastPayloadOf('grazing.create', () => true).then((last) => {
      if (!last) return
      if (last.mode === 'garde' || last.mode === 'autonomie') setMode(last.mode)
      if (last.zone) setZone(String(last.zone))
      if (last.plot_id) setPlotId(String(last.plot_id))
    })
  }, [])

  // L'effectif du lot choisi propose les têtes sorties et rentrées.
  const lot = batches.find((b) => b.id === Number(selected))
  useEffect(() => {
    if (!lot) return
    setSorties(String(lot.current_quantity))
    setRentrees(String(lot.current_quantity))
  }, [lot?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const date = aujourdhui()
  const departAt = new Date(`${date}T${depart}`)
  const retourAt = new Date(`${date}T${retour}`)
  const duree = Math.round((retourAt.getTime() - departAt.getTime()) / 60000)
  const nSorties = Number(sorties)
  const nRentrees = Number(rentrees)
  const manquants = Math.max(0, nSorties - nRentrees)
  const erreur =
    duree <= 0 ? t('L’heure de retour doit suivre l’heure de départ.')
      : duree > 16 * 60 ? t('Une sortie dure au plus 16 heures.')
        : lot && (nSorties < 1 || nSorties > lot.current_quantity) ? t('Têtes sorties : entre 1 et l’effectif du lot (:n).', { n: lot.current_quantity })
          : nRentrees > nSorties ? t('Plus de têtes rentrées que de têtes sorties.')
            : null
  const canSubmit = Boolean(selected) && !erreur && sorties !== '' && rentrees !== ''

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    await enqueue(
      'grazing.create',
      {
        uuid: crypto.randomUUID(),
        batch_id: Number(selected),
        left_at: departAt.toISOString(),
        returned_at: retourAt.toISOString(),
        mode,
        heads_out: nSorties,
        heads_back: nRentrees,
        herder_minutes: mode === 'autonomie' ? Number(herderMinutes) || 0 : null,
        plot_id: plotId ? Number(plotId) : null,
        zone: zone.trim() || null,
        notes: notes.trim() || null,
      },
      t('Pâturage :batch — :r/:s têtes', { batch: lot?.code ?? selected, r: nRentrees, s: nSorties }),
    )

    setSaved(manquants)
    setTimeout(() => navigate('/'), manquants > 0 ? 2500 : 1100)
  }

  if (saved !== null) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Sortie enregistrée')}</p>
        {saved > 0
          ? <p className="error">{t(':n tête(s) manquante(s) — l’élevage sera prévenu à la synchronisation.', { n: saved })}</p>
          : <p className="muted">{t('Le temps du berger sera imputé au lot à la synchronisation.')}</p>}
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>☀️ {t('Sortie au pâturage')}</h2>
      <p className="muted">{t('À noter au retour : où, combien de temps, et combien de têtes sont rentrées.')}</p>

      <label htmlFor="batch">{t('Lot')}</label>
      <select id="batch" required value={selected} onChange={(e) => setSelected(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {batches.map((b) => (
          <option key={b.id} value={b.id}>{b.code} · {b.current_quantity} {t('têtes')}</option>
        ))}
      </select>
      {batches.length === 0 && <p className="muted">{t('Aucun lot de ruminants actif en local — synchronisez d’abord.')}</p>}

      <div className="chip-row">
        <label style={{ flex: '1 1 120px' }}>{t('Départ')}
          <input type="time" required value={depart} onChange={(e) => setDepart(e.target.value)} />
        </label>
        <label style={{ flex: '1 1 120px' }}>{t('Retour')}
          <input type="time" required value={retour} onChange={(e) => setRetour(e.target.value)} />
        </label>
      </div>
      {duree > 0 && <p className="muted">{t('Durée : :h h', { h: (duree / 60).toFixed(1) })}</p>}

      <label>{t('Mode')}</label>
      <div className="chip-row">
        <button type="button" className={`chip ${mode === 'autonomie' ? 'chip-on' : ''}`} onClick={() => setMode('autonomie')}>{t('En autonomie')}</button>
        <button type="button" className={`chip ${mode === 'garde' ? 'chip-on' : ''}`} onClick={() => setMode('garde')}>{t('Gardé (berger)')}</button>
      </div>
      {mode === 'autonomie' && (
        <>
          <label htmlFor="herder">{t('Minutes passées à sortir puis ramener les bêtes')}</label>
          <input id="herder" type="number" inputMode="numeric" min={0} max={960} value={herderMinutes} onChange={(e) => setHerderMinutes(e.target.value)} />
        </>
      )}

      <div className="chip-row">
        <label style={{ flex: '1 1 120px' }}>{t('Têtes sorties')}
          <input type="number" inputMode="numeric" min={1} required value={sorties} onChange={(e) => setSorties(e.target.value)} />
        </label>
        <label style={{ flex: '1 1 120px' }}>{t('Têtes rentrées')}
          <input type="number" inputMode="numeric" min={0} required value={rentrees} onChange={(e) => setRentrees(e.target.value)} />
        </label>
      </div>
      {manquants > 0 && !erreur && <p className="error">⚠️ {t(':n tête(s) manquante(s) au retour.', { n: manquants })}</p>}

      {plots.length > 0 && (
        <select aria-label={t('Parcelle')} value={plotId} onChange={(e) => setPlotId(e.target.value)}>
          <option value="">{t('— Hors parcelle de la ferme —')}</option>
          {plots.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      )}
      <input aria-label={t('Zone')} maxLength={120} value={zone} onChange={(e) => setZone(e.target.value)} placeholder={t('Zone (ex. jachère nord, bord du marigot)')} />

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />

      {erreur && <p className="error">{erreur}</p>}
      <button type="submit" className="btn-primary" disabled={!canSubmit}>{t('Enregistrer la sortie')}</button>
    </form>
  )
}
