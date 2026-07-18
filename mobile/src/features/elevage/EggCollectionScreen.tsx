/**
 * Collecte d'œufs — saisie par passage (le serveur cumule les passages du
 * jour ; refusée si la journée est déjà triée). Contrat :
 * SyncService::eggCollectionCreate (gate production.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { NumberStepper } from '../../ui/NumberStepper'
import { t, dateLocale } from '../../i18n'
import type { RefBatch } from '../../api/types'

export function EggCollectionScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batch, setBatch] = useState<RefBatch | null>(null)
  const [totalEggs, setTotalEggs] = useState(0)
  const [brokenEggs, setBrokenEggs] = useState(0)
  const [smallEggs, setSmallEggs] = useState(0)
  const [observations, setObservations] = useState('')
  const [lastPass, setLastPass] = useState<{ eggs: number; date: string } | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (batchId) void db.ref_batches.get(Number(batchId)).then((b) => setBatch(b ?? null))
    // Anti-corvée : repère de vraisemblance — le nombre d'œufs est une
    // MESURE (jamais préremplie), mais afficher le dernier passage aide à
    // détecter une faute de frappe (1400 vs 140) d'un coup d'œil.
    if (batchId) {
      void lastPayloadOf('egg_collection.create', (p) => p.batch_id === Number(batchId)).then((last) => {
        if (last && typeof last.total_eggs_collected === 'number') {
          setLastPass({ eggs: last.total_eggs_collected, date: String(last.production_date ?? '') })
        }
      })
    }
  }, [batchId])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!batch || totalEggs <= 0) return

    await enqueue(
      'egg_collection.create',
      {
        batch_id: batch.id,
        production_date: new Date().toISOString().slice(0, 10),
        total_eggs_collected: totalEggs,
        broken_eggs: brokenEggs,
        small_eggs: smallEggs,
        observations: observations || null,
      },
      t('Collecte :code', { code: batch.code }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!batch) {
    return (
      <div className="screen">
        <p className="muted">{t("Lot introuvable en local — synchronisez d'abord.")}</p>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">✓ {t('Collecte enregistrée')}</p>
        <p className="muted">{t('Les passages du jour se cumulent automatiquement.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🥚 {t('Collecte')} — {batch.code}</h2>
      <p className="muted">
        {batch.current_quantity} {t('pondeuses')} · {new Date().toLocaleDateString(dateLocale())}
      </p>

      <NumberStepper label={t('Œufs ramassés (ce passage)')} value={totalEggs} onChange={setTotalEggs} min={0} step={10} />
      {lastPass && (
        <p className="muted">{t('↺ Dernier passage : :eggs œufs (:date).', { eggs: lastPass.eggs, date: lastPass.date })}</p>
      )}
      <NumberStepper label={t('Cassés / fêlés')} value={brokenEggs} onChange={setBrokenEggs} min={0} />
      <NumberStepper label={t('Petits calibres')} value={smallEggs} onChange={setSmallEggs} min={0} />

      <label htmlFor="observations">{t('Observations — optionnel')}</label>
      <textarea
        id="observations"
        rows={2}
        maxLength={500}
        value={observations}
        onChange={(e) => setObservations(e.target.value)}
      />

      <button type="submit" className="btn-primary" disabled={totalEggs <= 0}>
        {t('Enregistrer la collecte')}
      </button>
    </form>
  )
}
