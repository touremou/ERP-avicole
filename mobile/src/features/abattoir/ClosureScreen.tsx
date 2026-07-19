/**
 * Clôture de cycle d'abattage — checklist HACCP / déchets de fin de cycle.
 * Après l'exécution, on confirme le traitement des déchets (circuit séparé) et
 * le respect du plan sanitaire (nettoyage/désinfection, marche en avant). Les
 * trois confirmations sont OBLIGATOIRES (revérifiées serveur). Contrat :
 * SyncService::slaughterClose (gate abattoir.M).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefSlaughterOrder } from '../../api/types'

export function ClosureScreen() {
  const { orderId } = useParams()
  const navigate = useNavigate()
  const [order, setOrder] = useState<RefSlaughterOrder | null>(null)
  const [wasteEvacuated, setWasteEvacuated] = useState(false)
  const [zoneCleaned, setZoneCleaned] = useState(false)
  const [marcheAvant, setMarcheAvant] = useState(false)
  const [wasteDest, setWasteDest] = useState('')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (orderId) void db.ref_slaughter_orders.get(Number(orderId)).then((o) => setOrder(o ?? null))
  }, [orderId])

  const canSubmit = Boolean(orderId) && wasteEvacuated && zoneCleaned && marcheAvant

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    await enqueue(
      'slaughter.close',
      {
        slaughter_order_id: Number(orderId),
        waste_evacuated: true,
        zone_cleaned: true,
        marche_avant: true,
        waste_destination: wasteDest.trim() || null,
        notes: notes.trim() || null,
      },
      t('Clôture :order', { order: order?.order_number ?? orderId ?? '' }),
    )
    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Cycle clôturé')}</p>
        <p className="muted">{t('La checklist HACCP/déchets sera consolidée au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('✅ Clôture de cycle')}</h2>
      {order && <p className="muted">{order.order_number}</p>}

      <p className="proof-hint">{t('Checklist obligatoire')}</p>
      <label className="chk-row">
        <input type="checkbox" checked={wasteEvacuated} onChange={(e) => setWasteEvacuated(e.target.checked)} />
        <span>🗑️ {t('Déchets (sang, plumes, viscères) évacués vers la zone déchets / équarrissage.')}</span>
      </label>
      <label className="chk-row">
        <input type="checkbox" checked={zoneCleaned} onChange={(e) => setZoneCleaned(e.target.checked)} />
        <span>🧽 {t('Zones nettoyées et désinfectées.')}</span>
      </label>
      <label className="chk-row">
        <input type="checkbox" checked={marcheAvant} onChange={(e) => setMarcheAvant(e.target.checked)} />
        <span>➡️ {t('Marche en avant respectée — flux souillé/propre non croisés.')}</span>
      </label>

      <label htmlFor="dest">{t('Destination des déchets — optionnel')}</label>
      <input id="dest" type="text" maxLength={255} value={wasteDest} onChange={(e) => setWasteDest(e.target.value)} placeholder={t('Ex. équarrissage, compost...')} />

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Clôturer le cycle')}
      </button>
    </form>
  )
}
