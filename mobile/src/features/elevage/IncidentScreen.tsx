/**
 * Déclaration d'incident sanitaire — LA valeur ajoutée capteur du mobile :
 * la photo (autopsie, fientes) prise sur place, stockée hors-ligne (Dexie)
 * et téléversée au retour réseau AVANT le push de l'opération.
 * Contrat : SyncService::healthIncidentCreate (gate elevage.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { NumberStepper } from '../../ui/NumberStepper'
import { platform, compressImage } from '../../platform'
import type { RefBatch } from '../../api/types'

const SYMPTOM_PRESETS = [
  'Fientes vertes',
  'Fientes sanguinolentes',
  'Prostration',
  'Toux / râles',
  'Boiterie',
  'Chute de ponte',
  'Plumage ébouriffé',
]

export function IncidentScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batch, setBatch] = useState<RefBatch | null>(null)
  const [mortalityCount, setMortalityCount] = useState(0)
  const [symptoms, setSymptoms] = useState<string[]>([])
  const [details, setDetails] = useState('')
  const [severity, setSeverity] = useState<'mineur' | 'modere' | 'critique'>('modere')
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [photoBlob, setPhotoBlob] = useState<Blob | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (batchId) void db.ref_batches.get(Number(batchId)).then((b) => setBatch(b ?? null))
  }, [batchId])

  function toggleSymptom(symptom: string) {
    setSymptoms((current) =>
      current.includes(symptom) ? current.filter((s) => s !== symptom) : [...current, symptom],
    )
  }

  async function attachPhoto() {
    const file = await platform.takePhoto()
    if (!file) return
    // Compression avant stockage : réseau faible et quota IndexedDB.
    const compressed = await compressImage(file, 1280, 0.8)
    setPhotoBlob(compressed)
    setPhotoPreview(URL.createObjectURL(compressed))
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!batch) return

    const symptomsText = [...symptoms, details.trim()].filter(Boolean).join(', ')
    if (!symptomsText) return

    const payload: Record<string, unknown> = {
      batch_id: batch.id,
      incident_date: new Date().toISOString().slice(0, 10),
      mortality_count: mortalityCount,
      symptoms: symptomsText,
      severity,
    }

    if (photoBlob) {
      // La photo attend en local ; le moteur de sync la téléverse au retour
      // réseau et substitue photo_uuid → photo_path avant le push.
      const photoUuid = crypto.randomUUID()
      await db.photos.add({
        uuid: photoUuid,
        blob: photoBlob,
        context: 'incident',
        uploaded_path: null,
        created_at: new Date().toISOString(),
      })
      payload.photo_uuid = photoUuid
    }

    await enqueue('health_incident.create', payload, `Incident ${batch.code}`)

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!batch) {
    return (
      <div className="screen">
        <p className="muted">Lot introuvable en local — synchronisez d'abord.</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ Incident déclaré</p>
        <p className="muted">L'alerte partira au vétérinaire dès que le réseau le permet.</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🩺 Incident — {batch.code}</h2>

      <NumberStepper label="Cadavres constatés" value={mortalityCount} onChange={setMortalityCount} min={0} />

      <label>Symptômes observés</label>
      <div className="chip-row">
        {SYMPTOM_PRESETS.map((symptom) => (
          <button
            key={symptom}
            type="button"
            className={`chip ${symptoms.includes(symptom) ? 'chip-on' : ''}`}
            onClick={() => toggleSymptom(symptom)}
          >
            {symptom}
          </button>
        ))}
      </div>

      <label htmlFor="details">Autres constats — optionnel</label>
      <textarea id="details" rows={2} maxLength={1000} value={details} onChange={(e) => setDetails(e.target.value)} />

      <label>Gravité</label>
      <div className="chip-row">
        {(['mineur', 'modere', 'critique'] as const).map((level) => (
          <button
            key={level}
            type="button"
            className={`chip ${severity === level ? (level === 'critique' ? 'chip-danger' : 'chip-on') : ''}`}
            onClick={() => setSeverity(level)}
          >
            {level === 'modere' ? 'Modéré' : level.charAt(0).toUpperCase() + level.slice(1)}
          </button>
        ))}
      </div>

      <button type="button" className="btn-secondary" onClick={() => void attachPhoto()}>
        📷 {photoBlob ? 'Reprendre la photo' : 'Prendre une photo'}
      </button>
      {photoPreview && <img src={photoPreview} alt="Photo de l'incident" className="photo-preview" />}

      <button type="submit" className="btn-primary" disabled={symptoms.length === 0 && !details.trim()}>
        Déclarer l'incident
      </button>
    </form>
  )
}
