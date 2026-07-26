/**
 * Relevés de compteurs — eau et énergie lus SUR PLACE, devant le compteur.
 * Anti-corvée côté serveur : pour un groupe électrogène, ne saisir que les
 * heures suffit — carburant et coût sont estimés (conso horaire moyenne, dernier
 * prix au litre). Idempotents par nature : un relevé par (source, jour).
 * Contrats : SyncService::energyReadingCreate / waterReadingCreate (ressources.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefEnergySource, RefWaterSource } from '../../api/types'

export function MeterReadingScreen() {
  const navigate = useNavigate()

  const [kind, setKind] = useState<'energie' | 'eau'>('energie')
  const [energySources, setEnergySources] = useState<RefEnergySource[]>([])
  const [waterSources, setWaterSources] = useState<RefWaterSource[]>([])
  const [sourceId, setSourceId] = useState('')

  // Énergie
  const [hours, setHours] = useState('')
  const [fuel, setFuel] = useState('')
  const [outage, setOutage] = useState('')
  // Eau
  const [volume, setVolume] = useState('')
  const [ph, setPh] = useState('')
  const [chlorine, setChlorine] = useState('')

  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_energy_sources.toArray().then(setEnergySources)
    void db.ref_water_sources.toArray().then(setWaterSources)
  }, [])

  useEffect(() => { setSourceId(''); }, [kind])

  const energySource = energySources.find((s) => s.id === Number(sourceId)) ?? null
  const isGenerator = energySource?.type === 'groupe'
  const canSubmit = kind === 'energie'
    ? Boolean(sourceId) && hours !== '' && Number(hours) >= 0 && Number(hours) <= 24
    : Boolean(sourceId) && volume !== '' && Number(volume) >= 0

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return
    const today = new Date().toISOString().slice(0, 10)

    if (kind === 'energie') {
      await enqueue(
        'energy_reading.create',
        {
          energy_source_id: Number(sourceId),
          reading_date: today,
          hours_run: Number(hours),
          fuel_consumed_liters: fuel !== '' ? Number(fuel) : null,
          outage_hours: outage !== '' ? Number(outage) : null,
          notes: notes.trim() || null,
        },
        t('Relevé énergie :source — :h h', { source: energySource?.name ?? sourceId, h: Number(hours) }),
      )
    } else {
      const source = waterSources.find((s) => s.id === Number(sourceId))
      await enqueue(
        'water_reading.create',
        {
          water_source_id: Number(sourceId),
          reading_date: today,
          volume_consumed_liters: Number(volume),
          quality_ph: ph !== '' ? Number(ph) : null,
          chlorine_level: chlorine !== '' ? Number(chlorine) : null,
          notes: notes.trim() || null,
        },
        t('Relevé eau :source — :n L', { source: source?.name ?? sourceId, n: Number(volume) }),
      )
    }

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Relevé enregistré')}</p>
        <p className="muted">{t('Consommations, coûts et alertes seront calculés par le serveur au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🔢 {t('Relevé de compteur')}</h2>

      <div className="chip-row">
        <button type="button" className={`chip ${kind === 'energie' ? 'chip-on' : ''}`} onClick={() => setKind('energie')}>
          ⚡ {t('Énergie')}
        </button>
        <button type="button" className={`chip ${kind === 'eau' ? 'chip-on' : ''}`} onClick={() => setKind('eau')}>
          💧 {t('Eau')}
        </button>
      </div>

      {kind === 'energie' ? (
        <>
          <label htmlFor="src">{t('Source d’énergie')}</label>
          <select id="src" required value={sourceId} onChange={(e) => setSourceId(e.target.value)}>
            <option value="">{t('Sélectionner…')}</option>
            {energySources.map((s) => (
              <option key={s.id} value={s.id}>{s.name}{s.type ? ` · ${s.type}` : ''}</option>
            ))}
          </select>
          {energySources.length === 0 && (
            <p className="muted">{t('Aucune source d’énergie en local — synchronisez d’abord.')}</p>
          )}

          <label htmlFor="hours">{t('Heures de marche (0–24)')}</label>
          <input
            id="hours"
            type="number"
            inputMode="decimal"
            min={0}
            max={24}
            step="0.1"
            required
            value={hours}
            onChange={(e) => setHours(e.target.value)}
          />

          {isGenerator && (
            <>
              <label htmlFor="fuel">{t('Gasoil consommé (L) — laissez vide pour estimation')}</label>
              <input id="fuel" type="number" inputMode="decimal" min={0} step="0.1" value={fuel} onChange={(e) => setFuel(e.target.value)} />
              <p className="muted">{t('Vide → estimé par le serveur (heures × conso horaire moyenne) et valorisé au dernier prix au litre.')}</p>
            </>
          )}

          <label htmlFor="outage">{t('Heures de coupure réseau — optionnel')}</label>
          <input id="outage" type="number" inputMode="decimal" min={0} max={24} step="0.1" value={outage} onChange={(e) => setOutage(e.target.value)} />
        </>
      ) : (
        <>
          <label htmlFor="wsrc">{t('Citerne')}</label>
          <select id="wsrc" required value={sourceId} onChange={(e) => setSourceId(e.target.value)}>
            <option value="">{t('Sélectionner…')}</option>
            {waterSources.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
          {waterSources.length === 0 && (
            <p className="muted">{t('Aucune citerne — la synchronisation les rapatriera au premier passage réseau.')}</p>
          )}

          <label htmlFor="vol">{t('Volume consommé (L)')}</label>
          <input
            id="vol"
            type="number"
            inputMode="decimal"
            min={0}
            step="1"
            required
            value={volume}
            onChange={(e) => setVolume(e.target.value)}
          />

          <label htmlFor="ph">{t('pH — optionnel')}</label>
          <input id="ph" type="number" inputMode="decimal" min={0} max={14} step="0.1" value={ph} onChange={(e) => setPh(e.target.value)} />

          <label htmlFor="cl">{t('Chlore (mg/L) — optionnel')}</label>
          <input id="cl" type="number" inputMode="decimal" min={0} max={10} step="0.1" value={chlorine} onChange={(e) => setChlorine(e.target.value)} />
        </>
      )}

      <label htmlFor="notes">{t('Note — optionnel')}</label>
      <input id="notes" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer le relevé')}
      </button>
    </form>
  )
}
