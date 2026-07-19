/**
 * Tournée de températures — TOUS les points de contrôle sur un seul écran :
 * l'opérateur fait le tour avec son thermomètre et remplit ce qu'il a relevé
 * (lignes vides ignorées), une seule validation → une op de sync PAR point
 * rempli (idempotence et bac « À corriger » ligne par ligne inchangés).
 * Contrat : SyncService::temperatureLogCreate (gate abattoir.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { t } from '../../i18n'

/** Miroir de App\Models\TemperatureLog::POINT_LABELS. */
const POINTS = [
  { value: 'chambre_froide_positive', label: 'Chambre froide positive' },
  { value: 'congelation', label: 'Congélation' },
  { value: 'salle_decoupe', label: 'Salle de découpe' },
  { value: 'echaudage', label: 'Échaudage' },
  { value: 'vehicule', label: 'Véhicule frigorifique' },
] as const

type Row = { temperature: string; equipmentRef: string; corrective: string }

export function TemperatureRoundScreen() {
  const navigate = useNavigate()
  const [rows, setRows] = useState<Record<string, Row>>(
    Object.fromEntries(POINTS.map((p) => [p.value, { temperature: '', equipmentRef: '', corrective: '' }])),
  )
  const [saved, setSaved] = useState(0)

  // Anti-corvée : rappelle l'équipement du dernier relevé local de chaque point.
  useEffect(() => {
    for (const p of POINTS) {
      void lastPayloadOf('temperature_log.create', (payload) => payload.point === p.value).then((last) => {
        if (last && typeof last.equipment_ref === 'string') {
          setRows((prev) => ({ ...prev, [p.value]: { ...prev[p.value], equipmentRef: last.equipment_ref as string } }))
        }
      })
    }
  }, [])

  function setRow(point: string, patch: Partial<Row>) {
    setRows((prev) => ({ ...prev, [point]: { ...prev[point], ...patch } }))
  }

  const filled = POINTS.filter((p) => {
    const v = rows[p.value].temperature.trim()
    return v !== '' && !Number.isNaN(Number(v))
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (filled.length === 0) return

    for (const p of filled) {
      const row = rows[p.value]
      await enqueue(
        'temperature_log.create',
        {
          point: p.value,
          equipment_ref: row.equipmentRef.trim() || null,
          temperature: Number(row.temperature),
          corrective_action: row.corrective.trim() || null,
          releve_at: new Date().toISOString(),
        },
        t('Température :point : :temp °C', { point: t(p.label), temp: Number(row.temperature) }),
      )
    }

    setSaved(filled.length)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved > 0) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Tournée enregistrée (:n relevés)', { n: saved })}</p>
        <p className="muted">{t('La conformité sera évaluée par le serveur selon les seuils réglés.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🌡️ {t('Tournée de températures')}</h2>
      <p className="muted">{t('Remplissez les points relevés, laissez vides les autres — une seule validation.')}</p>

      {POINTS.map((p) => {
        const row = rows[p.value]
        const has = row.temperature.trim() !== ''
        return (
          <div key={p.value} className="round-row">
            <div className="cut-line">
              <span className="cut-label">{t(p.label)}</span>
              <input
                type="number"
                inputMode="decimal"
                min={-60}
                max={120}
                step="0.1"
                value={row.temperature}
                onChange={(e) => setRow(p.value, { temperature: e.target.value })}
                placeholder="—.- °C"
                aria-label={t(p.label)}
              />
            </div>
            {has && (
              <div className="round-detail">
                <input
                  maxLength={50}
                  value={row.equipmentRef}
                  onChange={(e) => setRow(p.value, { equipmentRef: e.target.value })}
                  placeholder={t('Équipement (ex. CF-01)')}
                />
                <input
                  maxLength={2000}
                  value={row.corrective}
                  onChange={(e) => setRow(p.value, { corrective: e.target.value })}
                  placeholder={t('Action corrective si hors seuil')}
                />
              </div>
            )}
          </div>
        )
      })}

      <button type="submit" className="btn-primary" disabled={filled.length === 0}>
        {t('Valider la tournée (:n relevés)', { n: filled.length })}
      </button>
      <Link to="/abattoir/temperature" className="muted center-link">{t('Relevé isolé →')}</Link>
    </form>
  )
}
