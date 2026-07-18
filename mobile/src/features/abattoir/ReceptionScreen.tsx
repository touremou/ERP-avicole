/**
 * Réception du vif (contrôle ante-mortem, CCP 1) — l'éleveur livreur vient
 * du miroir local ref_providers, la photo du certificat suit le pipeline
 * hors-ligne des dépenses (Dexie → upload → photo_path). Un motif est
 * OBLIGATOIRE dès que la décision n'est pas « accepté ».
 * Contrat : SyncService::slaughterReceptionCreate (gate abattoir.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { safeLoad } from '../../offline/safeLoad'
import { enqueue } from '../../offline/sync'
import { platform, compressImage } from '../../platform'
import { t } from '../../i18n'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefProvider } from '../../api/types'

/** Miroir de App\Models\SlaughterReception (référentiels stables). */
const SANITARY_STATES = [
  { value: 'conforme', label: 'Conforme' },
  { value: 'reserves', label: 'Réserves' },
  { value: 'non_conforme', label: 'Non conforme' },
] as const

const FASTING = [
  { value: 'oui', label: 'Diète respectée' },
  { value: 'non', label: 'Non respectée' },
  { value: 'partielle', label: 'Partielle' },
] as const

const DECISIONS = [
  { value: 'accepte', label: '✅ Accepté' },
  { value: 'accepte_avec_decote', label: '⚠️ Décote' },
  { value: 'refuse', label: '⛔ Refusé' },
] as const

export function ReceptionScreen() {
  const navigate = useNavigate()
  const [providers, setProviders] = useState<RefProvider[]>([])
  const [providerId, setProviderId] = useState('')
  const [announced, setAnnounced] = useState(0)
  const [received, setReceived] = useState(0)
  const [rejected, setRejected] = useState(0)
  const [liveWeight, setLiveWeight] = useState('')
  const [sanitaryState, setSanitaryState] = useState<(typeof SANITARY_STATES)[number]['value']>('conforme')
  const [fasting, setFasting] = useState<(typeof FASTING)[number]['value']>('oui')
  const [decision, setDecision] = useState<(typeof DECISIONS)[number]['value']>('accepte')
  const [reason, setReason] = useState('')
  const [photoBlob, setPhotoBlob] = useState<Blob | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void safeLoad('reception:fournisseurs', async () => setProviders(await db.ref_providers.orderBy('name').toArray()))
  }, [])

  async function attachPhoto() {
    const file = await platform.takePhoto()
    if (!file) return
    const compressed = await compressImage(file, 1280, 0.8)
    setPhotoBlob(compressed)
    setPhotoPreview(URL.createObjectURL(compressed))
  }

  const weight = Number(liveWeight)
  const reasonRequired = decision !== 'accepte'
  const valid =
    Boolean(providerId) && received > 0 && rejected <= received && weight > 0 &&
    (!reasonRequired || reason.trim().length > 0)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!valid) return

    const payload: Record<string, unknown> = {
      provider_id: Number(providerId),
      reception_date: new Date().toISOString().slice(0, 10),
      announced_quantity: announced || null,
      received_quantity: received,
      rejected_quantity: rejected || null,
      total_live_weight_kg: weight,
      sanitary_state: sanitaryState,
      fasting_respected: fasting,
      decision,
      decision_reason: reasonRequired ? reason.trim() : null,
      releve_at: new Date().toISOString(),
    }

    if (photoBlob) {
      const photoUuid = crypto.randomUUID()
      await db.photos.add({
        uuid: photoUuid,
        blob: photoBlob,
        context: 'reception',
        uploaded_path: null,
        created_at: new Date().toISOString(),
      })
      payload.photo_uuid = photoUuid
    }

    const provider = providers.find((p) => p.id === Number(providerId))
    await enqueue(
      'slaughter_reception.create',
      payload,
      t('Réception vif :name (:qty sujets)', { name: provider?.name ?? providerId, qty: received }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Réception enregistrée')}</p>
        <p className="muted">{t('Le registre ante-mortem sera consolidé au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🚚 Réception du vif')}</h2>

      <label htmlFor="provider">{t('Éleveur livreur')}</label>
      <select id="provider" required value={providerId} onChange={(e) => setProviderId(e.target.value)}>
        <option value="" disabled>
          {t('— Choisir un éleveur —')}
        </option>
        {providers.map((provider) => (
          <option key={provider.id} value={provider.id}>
            {provider.name}
          </option>
        ))}
      </select>
      {providers.length === 0 && (
        <p className="muted">{t("Aucun éleveur local — synchronisez d'abord.")}</p>
      )}

      <NumberStepper label={t('Sujets annoncés — optionnel')} value={announced} onChange={setAnnounced} min={0} />
      <NumberStepper label={t('Sujets reçus')} value={received} onChange={setReceived} min={0} />
      <NumberStepper label={t('Sujets écartés')} value={rejected} onChange={setRejected} min={0} />
      {rejected > received && <p className="error">{t('⚠️ Les écartés ne peuvent pas dépasser les reçus.')}</p>}

      <label htmlFor="weight">{t('Poids vif total (kg)')}</label>
      <input
        id="weight"
        type="number"
        inputMode="decimal"
        min={0.1}
        step="0.1"
        required
        value={liveWeight}
        onChange={(e) => setLiveWeight(e.target.value)}
        placeholder={t('ex. 120.5')}
      />

      <label>{t('État sanitaire (inspection visuelle)')}</label>
      <div className="chip-row">
        {SANITARY_STATES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${sanitaryState === option.value ? 'chip-on' : ''}`}
            onClick={() => setSanitaryState(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label>{t('Diète pré-abattage')}</label>
      <div className="chip-row">
        {FASTING.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${fasting === option.value ? 'chip-on' : ''}`}
            onClick={() => setFasting(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label>{t('Décision')}</label>
      <div className="chip-row">
        {DECISIONS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${decision === option.value ? 'chip-on' : ''}`}
            onClick={() => setDecision(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      {reasonRequired && (
        <>
          <label htmlFor="reason">{t('Motif de la décision (obligatoire)')}</label>
          <textarea
            id="reason"
            rows={2}
            required
            maxLength={1000}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={t('ex. Diète non respectée, lot hétérogène')}
          />
        </>
      )}

      <button type="button" className="btn-secondary" onClick={() => void attachPhoto()}>
        📷 {photoBlob ? t('Reprendre le certificat') : t('Photographier le certificat')}
      </button>
      {photoPreview && <img src={photoPreview} alt={t('Certificat')} className="photo-preview" />}

      <button type="submit" className="btn-primary" disabled={!valid}>
        {t('Enregistrer la réception')}
      </button>
    </form>
  )
}
