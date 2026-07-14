/**
 * Registre nettoyage / désinfection — trace simple insert-only, avec photo
 * optionnelle (même pipeline hors-ligne que les dépenses).
 * Contrat : SyncService::cleaningLogCreate (gate abattoir.C).
 */
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { platform, compressImage } from '../../platform'
import { t } from '../../i18n'

/** Miroir de App\Models\CleaningLog::ZONES (référentiel stable). */
const ZONES = [
  { value: 'surfaces_tables', label: 'Surfaces et tables' },
  { value: 'sols_siphons', label: 'Sols et siphons' },
  { value: 'couteaux_materiel', label: 'Couteaux et petit matériel' },
  { value: 'chambre_froide', label: 'Chambres froides' },
  { value: 'vehicule', label: 'Véhicules frigorifiques' },
  { value: 'zone_dechets', label: 'Zone déchets' },
  { value: 'autre', label: 'Autre' },
] as const

export function CleaningScreen() {
  const navigate = useNavigate()
  const [zone, setZone] = useState<(typeof ZONES)[number]['value']>('surfaces_tables')
  const [productUsed, setProductUsed] = useState('')
  const [dosage, setDosage] = useState('')
  const [notes, setNotes] = useState('')
  const [photoBlob, setPhotoBlob] = useState<Blob | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  async function attachPhoto() {
    const file = await platform.takePhoto()
    if (!file) return
    const compressed = await compressImage(file, 1280, 0.8)
    setPhotoBlob(compressed)
    setPhotoPreview(URL.createObjectURL(compressed))
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!productUsed.trim()) return

    const payload: Record<string, unknown> = {
      zone,
      product_used: productUsed.trim(),
      dosage: dosage.trim() || null,
      notes: notes.trim() || null,
      done_at: new Date().toISOString(),
    }

    if (photoBlob) {
      const photoUuid = crypto.randomUUID()
      await db.photos.add({
        uuid: photoUuid,
        blob: photoBlob,
        context: 'cleaning',
        uploaded_path: null,
        created_at: new Date().toISOString(),
      })
      payload.photo_uuid = photoUuid
    }

    const zoneLabel = ZONES.find((option) => option.value === zone)?.label ?? zone
    await enqueue(
      'cleaning_log.create',
      payload,
      t('Nettoyage :zone (:product)', { zone: t(zoneLabel), product: productUsed.trim() }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Nettoyage enregistré')}</p>
        <p className="muted">{t('Le registre de nettoyage sera consolidé au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🧽 Nettoyage / désinfection')}</h2>

      <label>{t('Zone')}</label>
      <div className="chip-row">
        {ZONES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${zone === option.value ? 'chip-on' : ''}`}
            onClick={() => setZone(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label htmlFor="product">{t('Produit utilisé')}</label>
      <input
        id="product"
        required
        maxLength={100}
        value={productUsed}
        onChange={(e) => setProductUsed(e.target.value)}
        placeholder={t('ex. Eau de Javel 12°')}
      />

      <label htmlFor="dosage">{t('Dosage — optionnel')}</label>
      <input
        id="dosage"
        maxLength={50}
        value={dosage}
        onChange={(e) => setDosage(e.target.value)}
        placeholder={t('ex. 20 ml/L')}
      />

      <label htmlFor="notes">{t('Notes — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="button" className="btn-secondary" onClick={() => void attachPhoto()}>
        📷 {photoBlob ? t('Reprendre la photo') : t('Photographier la zone')}
      </button>
      {photoPreview && <img src={photoPreview} alt={t('Zone nettoyée')} className="photo-preview" />}

      <button type="submit" className="btn-primary" disabled={!productUsed.trim()}>
        {t('Enregistrer le nettoyage')}
      </button>
    </form>
  )
}
