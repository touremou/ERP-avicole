/**
 * Preuve d'exécution (Proof of Work) — modale de complétion d'une tâche qui
 * EXIGE une preuve :
 *   - photo : capture obligatoire (compressée en local, pipeline hors-ligne
 *     identique aux dépenses/réceptions → db.photos → photo_path au push) ;
 *   - valeur : donnée chiffrée précise (nombre de morts, poids…).
 *
 * La modale effectue elle-même l'enqueue de `task.complete` (avec la preuve)
 * et retire la tâche du miroir local — les deux écrans (Accueil, Mes tâches)
 * la réutilisent. Le serveur revérifie la preuve (autoritaire) : impossible de
 * clôturer sans, même hors-ligne (le refus tombe en « À corriger » au push).
 */
import { useState } from 'react'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { platform, compressImage } from '../../platform'
import { t } from '../../i18n'
import type { RefTask } from '../../api/types'

interface Props {
  task: RefTask
  onDone: () => void
  onCancel: () => void
}

export function TaskProofModal({ task, onDone, onCancel }: Props) {
  const isPhoto = task.proof_type === 'photo'
  const [photoBlob, setPhotoBlob] = useState<Blob | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [value, setValue] = useState('')
  const [saving, setSaving] = useState(false)

  const numeric = Number(value)
  const canSubmit = isPhoto ? photoBlob !== null : value.trim() !== '' && !Number.isNaN(numeric) && numeric >= 0

  async function capture() {
    const file = await platform.takePhoto()
    if (!file) return
    const compressed = await compressImage(file, 1280, 0.8)
    setPhotoBlob(compressed)
    setPhotoPreview(URL.createObjectURL(compressed))
  }

  async function submit() {
    if (!canSubmit || saving || task.id < 0) return
    setSaving(true)
    try {
      const payload: Record<string, unknown> = { task_id: task.id }

      if (isPhoto && photoBlob) {
        const photoUuid = crypto.randomUUID()
        await db.photos.add({
          uuid: photoUuid, blob: photoBlob, context: 'task',
          uploaded_path: null, created_at: new Date().toISOString(),
        })
        payload.photo_uuid = photoUuid
      } else {
        payload.proof_value = numeric
      }

      await enqueue('task.complete', payload, t('Tâche : :title', { title: task.title }))
      await db.tasks.delete(task.id)
      window.dispatchEvent(new CustomEvent('tasks:updated'))
      onDone()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true">
      <div className="modal-card">
        <h3>{t('Preuve requise')}</h3>
        <p className="muted">{task.title}</p>

        {isPhoto ? (
          <>
            <p className="proof-hint">📸 {t(task.proof_label || 'Photo obligatoire')}</p>
            {photoPreview ? (
              <img src={photoPreview} alt={t('Aperçu')} className="proof-photo" />
            ) : null}
            <button type="button" className="btn-primary" onClick={() => void capture()}>
              {photoBlob ? t('Reprendre la photo') : '📷 ' + t('Prendre la photo')}
            </button>
          </>
        ) : (
          <>
            <label htmlFor="proof-value">{t(task.proof_label || 'Valeur mesurée')}{task.proof_unit ? ` (${task.proof_unit})` : ''}</label>
            <input
              id="proof-value"
              type="number"
              inputMode="decimal"
              min={0}
              step="any"
              value={value}
              autoFocus
              onFocus={(e) => e.target.select()}
              onChange={(e) => setValue(e.target.value)}
              placeholder={t('ex. 3')}
            />
          </>
        )}

        <div className="modal-actions">
          <button type="button" className="btn-ghost" onClick={onCancel} disabled={saving}>
            {t('Annuler')}
          </button>
          <button type="button" className="btn-primary" onClick={() => void submit()} disabled={!canSubmit || saving}>
            {saving ? t('Enregistrement…') : '✓ ' + t('Valider avec preuve')}
          </button>
        </div>
      </div>
    </div>
  )
}
