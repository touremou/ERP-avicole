/**
 * Relevé de température (registre HACCP, CCP 4 chaîne du froid) — la
 * conformité est calculée SERVEUR selon les seuils des Réglages abattoir ;
 * le terrain saisit la mesure et, si besoin, l'action corrective.
 * Contrat : SyncService::temperatureLogCreate (gate abattoir.C).
 */
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'

/** Miroir de App\Models\TemperatureLog::POINT_LABELS. */
const POINTS = [
  { value: 'chambre_froide_positive', label: 'Chambre froide positive' },
  { value: 'congelation', label: 'Congélation' },
  { value: 'salle_decoupe', label: 'Salle de découpe' },
  { value: 'echaudage', label: 'Échaudage' },
  { value: 'vehicule', label: 'Véhicule frigorifique' },
] as const

export function TemperatureScreen() {
  const navigate = useNavigate()
  const [point, setPoint] = useState<(typeof POINTS)[number]['value']>('chambre_froide_positive')
  const [equipmentRef, setEquipmentRef] = useState('')
  const [temperature, setTemperature] = useState('')
  const [correctiveAction, setCorrectiveAction] = useState('')
  const [saved, setSaved] = useState(false)

  const valid = temperature.trim() !== '' && !Number.isNaN(Number(temperature))

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!valid) return

    const label = POINTS.find((option) => option.value === point)?.label ?? point
    await enqueue(
      'temperature_log.create',
      {
        point,
        equipment_ref: equipmentRef.trim() || null,
        temperature: Number(temperature),
        corrective_action: correctiveAction.trim() || null,
        releve_at: new Date().toISOString(),
      },
      t('Température :point : :temp °C', { point: t(label), temp: Number(temperature) }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Relevé enregistré')}</p>
        <p className="muted">{t('La conformité sera évaluée par le serveur selon les seuils réglés.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🌡️ Relevé de température')}</h2>

      <label>{t('Point de contrôle')}</label>
      <div className="chip-row">
        {POINTS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${point === option.value ? 'chip-on' : ''}`}
            onClick={() => setPoint(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label htmlFor="equipment">{t('Équipement — optionnel')}</label>
      <input
        id="equipment"
        maxLength={50}
        value={equipmentRef}
        onChange={(e) => setEquipmentRef(e.target.value)}
        placeholder={t('ex. CF-01')}
      />

      <label htmlFor="temperature">{t('Température (°C)')}</label>
      <input
        id="temperature"
        type="number"
        inputMode="decimal"
        min={-60}
        max={120}
        step="0.1"
        required
        value={temperature}
        onChange={(e) => setTemperature(e.target.value)}
        placeholder={t('ex. 2.5 ou -18')}
      />

      <label htmlFor="corrective">{t('Action corrective — optionnel')}</label>
      <textarea
        id="corrective"
        rows={2}
        maxLength={2000}
        value={correctiveAction}
        onChange={(e) => setCorrectiveAction(e.target.value)}
      />
      <p className="muted">
        {t('Obligatoire si hors seuil — le serveur évalue selon les seuils réglés.')}
      </p>

      <button type="submit" className="btn-primary" disabled={!valid}>
        {t('Enregistrer le relevé')}
      </button>
    </form>
  )
}
