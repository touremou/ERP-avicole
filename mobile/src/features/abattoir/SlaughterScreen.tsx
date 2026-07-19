/**
 * Exécution d'abattage — l'ordre est planifié au bureau (web), le terrain
 * saisit les quantités et pesées réelles. Les gardes métier (quarantaine,
 * effectif, statut sous verrou) sont rejouées par le serveur au push :
 * un refus sort vers le bac « À corriger ». Contrat :
 * SyncService::slaughterExecute (gate abattoir.M).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { dateLocale, t } from '../../i18n'
import { NumberStepper } from '../../ui/NumberStepper'
import { VoiceDictation } from '../../ui/VoiceDictation'
import type { RefBatch, RefSlaughterOrder } from '../../api/types'

// Gammes de sortie carcasse (miroir de config/butchery.php, volaille) : bande
// de rendement attendue [alerte_min, cible_max] — l'effilé garde tête/pattes,
// donc rendement plus haut. Le serveur reste l'autorité sur le nom d'article.
const PRESENTATIONS = [
  { value: 'brut', label: '🔪 Brut (à découper)', min: 65, max: 75 },
  { value: 'pac', label: '📦 PAC (prêt-à-cuire)', min: 65, max: 75 },
  { value: 'effile', label: '🍗 Effilé (têtes/pattes)', min: 77, max: 87 },
] as const

export function SlaughterScreen() {
  const { orderId } = useParams()
  const navigate = useNavigate()

  const [order, setOrder] = useState<RefSlaughterOrder | null>(null)
  const [batch, setBatch] = useState<RefBatch | null>(null)
  const [actualQuantity, setActualQuantity] = useState(0)
  const [liveWeight, setLiveWeight] = useState('')
  const [carcassWeight, setCarcassWeight] = useState('')
  const [condemned, setCondemned] = useState(0)
  const [presentation, setPresentation] = useState<(typeof PRESENTATIONS)[number]['value']>('brut')
  const [notes, setNotes] = useState('')
  // Anti-corvée : le CCP 3 (T° à cœur) se saisit dans le MÊME geste —
  // une seconde opération part dans la file, pas de second écran.
  const [coreTemp, setCoreTemp] = useState('')
  const [ccpAction, setCcpAction] = useState('')
  const [saved, setSaved] = useState(false)
  // Verrouillage du cycle : une exécution déjà en file (offline, pas encore
  // poussée) rend l'ordre non re-sélectionnable AVANT même le pull serveur.
  const [alreadyQueued, setAlreadyQueued] = useState(false)

  useEffect(() => {
    if (!orderId) return
    void db.ref_slaughter_orders.get(Number(orderId)).then(async (found) => {
      setOrder(found ?? null)
      setActualQuantity(found?.planned_quantity ?? 0)
      if (found?.batch_id) setBatch((await db.ref_batches.get(found.batch_id)) ?? null)
      const queued = await db.outbox
        .filter((op) => op.type === 'slaughter.execute' && op.payload.slaughter_order_id === Number(orderId))
        .count()
      setAlreadyQueued(queued > 0)
    })
  }, [orderId])

  const live = Number(liveWeight)
  const carcass = Number(carcassWeight)
  const weightsValid = live > 0 && carcass > 0 && carcass <= live
  const yieldPercent = weightsValid ? Math.round((carcass / live) * 1000) / 10 : null
  const band = PRESENTATIONS.find((p) => p.value === presentation) ?? PRESENTATIONS[0]
  const yieldOff = yieldPercent !== null && (yieldPercent < band.min || yieldPercent > band.max)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!order || actualQuantity <= 0 || !weightsValid) return

    await enqueue(
      'slaughter.execute',
      {
        slaughter_order_id: order.id,
        execution_date: new Date().toISOString().slice(0, 10),
        actual_quantity: actualQuantity,
        total_live_weight_kg: live,
        total_carcass_weight_kg: carcass,
        condemned_count: condemned,
        presentation,
        inspector_notes: notes || null,
      },
      t('Abattage :order (:qty sujets)', { order: order.order_number, qty: actualQuantity }),
    )

    // CCP 3 dans la foulée (file FIFO : part APRÈS l'exécution). La
    // conformité est évaluée serveur ; hors seuil sans action → bac
    // « À corriger », le geste d'abattage n'est jamais perdu.
    if (coreTemp.trim() !== '' && !Number.isNaN(Number(coreTemp))) {
      await enqueue(
        'ccp_record.create',
        {
          ccp: 'ccp3_refroidissement',
          slaughter_order_id: order.id,
          mesures: { temperature_coeur: Number(coreTemp) },
          corrective_action: ccpAction.trim() || null,
          releve_at: new Date().toISOString(),
        },
        t('CCP 3 :order : :temp °C', { order: order.order_number, temp: Number(coreTemp) }),
      )
    }

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (!order) {
    return (
      <div className="screen">
        <p className="muted">{t("Ordre d'abattage introuvable en local — synchronisez d'abord.")}</p>
      </div>
    )
  }

  // Verrouillage du cycle : un ordre ne s'exécute qu'une fois. Déjà terminé
  // (statut serveur) ou déjà en file locale → on oriente vers la suite du
  // cycle (clôture) au lieu de laisser ressaisir un abattage en double.
  if (order.status !== 'planifie' || alreadyQueued) {
    const closed = Boolean(order.closed_at)
    return (
      <div className="screen-center">
        <p className="big">🔒 {t('Ordre :order déjà exécuté', { order: order.order_number })}</p>
        <p className="muted">
          {closed
            ? t('Cycle clôturé — la checklist HACCP/déchets est déjà signée. Plus aucune action possible.')
            : alreadyQueued && order.status === 'planifie'
              ? t("L'exécution est déjà dans la file de synchronisation — elle partira au prochain push.")
              : t('Cet ordre ne peut pas être ré-exécuté. Suite du cycle : clôture HACCP/déchets.')}
        </p>
        {!closed && (
          <button type="button" className="btn-primary" onClick={() => navigate(`/abattoir/cloture/${order.id}`)}>
            ✅ {t('Clôturer le cycle (checklist HACCP/déchets)')}
          </button>
        )}
        <button type="button" className="btn-secondary" onClick={() => navigate('/')}>
          {t('Retour à l’accueil')}
        </button>
      </div>
    )
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Abattage enregistré')}</p>
        <p className="muted">{t('Quarantaine et effectif seront re-vérifiés par le serveur au push.')}</p>
        {/* Fin de cycle : clôture HACCP/déchets (part APRÈS l'exécution au push). */}
        <button type="button" className="btn-primary" onClick={() => navigate(`/abattoir/cloture/${order.id}`)}>
          ✅ {t('Clôturer le cycle (checklist HACCP/déchets)')}
        </button>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('🔪 Abattage — :order', { order: order.order_number })}</h2>
      <p className="muted">
        {batch ? `${t('Lot :code', { code: batch.code })} · ` : ''}
        {t(':qty sujets planifiés', { qty: order.planned_quantity })} · {new Date().toLocaleDateString(dateLocale())}
      </p>

      <label>{t('Gamme de sortie carcasse')}</label>
      <div className="chip-row">
        {PRESENTATIONS.map((p) => (
          <button key={p.value} type="button" className={`chip ${presentation === p.value ? 'chip-on' : ''}`} onClick={() => setPresentation(p.value)}>
            {t(p.label)}
          </button>
        ))}
      </div>

      <NumberStepper label={t('Sujets abattus')} value={actualQuantity} onChange={setActualQuantity} min={0} />
      {batch && actualQuantity > batch.current_quantity && (
        <p className="error">
          {t("⚠️ Supérieur à l'effectif local connu (:count) — le serveur tranchera au push.", { count: batch.current_quantity })}
        </p>
      )}

      <label htmlFor="live">{t('Poids vif total (kg)')}</label>
      <input
        id="live"
        type="number"
        inputMode="decimal"
        min={0.1}
        step="0.1"
        required
        value={liveWeight}
        onChange={(e) => setLiveWeight(e.target.value)}
        placeholder={t('ex. 120.5')}
      />

      <label htmlFor="carcass">{t('Poids carcasse total (kg)')}</label>
      <input
        id="carcass"
        type="number"
        inputMode="decimal"
        min={0.1}
        step="0.1"
        required
        value={carcassWeight}
        onChange={(e) => setCarcassWeight(e.target.value)}
        placeholder={t('ex. 90.0')}
      />
      {live > 0 && carcass > live && (
        <p className="error">{t('⚠️ La carcasse ne peut pas peser plus que le vif.')}</p>
      )}
      {yieldPercent !== null && (
        <p className={yieldOff ? 'error' : 'muted'}>
          {t('Rendement carcasse : :pct % (attendu :min–:max %)', { pct: yieldPercent, min: band.min, max: band.max })}
          {yieldOff ? ' ⚠️' : ''}
        </p>
      )}

      <NumberStepper label={t('Saisies / condamnés')} value={condemned} onChange={setCondemned} min={0} />

      <label htmlFor="notes">{t("Notes d'inspection — optionnel")}</label>
      <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <div className="chip-row">
        <VoiceDictation onText={(text) => setNotes((prev) => (prev ? prev + ' ' : '') + text)} />
      </div>

      <label htmlFor="coretemp">{t('🛡️ CCP 3 — T° à cœur après refroidissement (°C) — recommandé')}</label>
      <input
        id="coretemp"
        type="number"
        inputMode="decimal"
        min={-10}
        max={60}
        step="0.1"
        value={coreTemp}
        onChange={(e) => setCoreTemp(e.target.value)}
        placeholder={t('ex. 3.4')}
      />
      {coreTemp.trim() !== '' && (
        <>
          <p className="muted">{t('Le relevé CCP 3 part avec l’abattage — plus rien à ressaisir au registre.')}</p>
          <label htmlFor="ccpaction">{t('Action corrective (si hors seuil)')}</label>
          <textarea id="ccpaction" rows={2} maxLength={2000} value={ccpAction} onChange={(e) => setCcpAction(e.target.value)} />
        </>
      )}

      <button type="submit" className="btn-primary" disabled={actualQuantity <= 0 || !weightsValid}>
        {t("Enregistrer l'abattage")}
      </button>
    </form>
  )
}
