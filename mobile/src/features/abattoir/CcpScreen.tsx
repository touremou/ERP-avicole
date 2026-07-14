/**
 * Relevé CCP (plan HACCP abattoir) — le terrain saisit les mesures et une
 * déclaration de conformité ; la conformité FINALE est évaluée par le
 * serveur selon les seuils HACCP des Réglages (non conforme sans action
 * corrective → refus au push). Contrat : SyncService::ccpRecordCreate
 * (gate abattoir.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import { NumberStepper } from '../../ui/NumberStepper'
import type { RefSlaughterOrder } from '../../api/types'

/** Miroir de App\Models\CcpRecord::CCPS. */
const CCPS = [
  { value: 'ccp1_reception', label: 'CCP 1 — Réception' },
  { value: 'ccp2_evisceration', label: 'CCP 2 — Éviscération' },
  { value: 'ccp3_refroidissement', label: 'CCP 3 — Refroidissement' },
  { value: 'ccp4_chaine_froid', label: 'CCP 4 — Chaîne du froid' },
] as const

type Ccp = (typeof CCPS)[number]['value']

/** Miroir de App\Models\TemperatureLog::POINT_LABELS (mesure du CCP 4). */
const COLD_POINTS = [
  { value: 'chambre_froide_positive', label: 'Chambre froide positive' },
  { value: 'congelation', label: 'Congélation' },
  { value: 'salle_decoupe', label: 'Salle de découpe' },
  { value: 'vehicule', label: 'Véhicule frigorifique' },
] as const

export function CcpScreen() {
  const navigate = useNavigate()
  const [ccp, setCcp] = useState<Ccp>('ccp1_reception')
  const [orders, setOrders] = useState<RefSlaughterOrder[]>([])
  const [orderId, setOrderId] = useState('')
  const [equipmentRef, setEquipmentRef] = useState('')
  // Mesures par CCP.
  const [appreciation, setAppreciation] = useState<'conforme' | 'non_conforme'>('conforme')
  const [carcassesTotal, setCarcassesTotal] = useState(0)
  const [carcassesSouillees, setCarcassesSouillees] = useState(0)
  const [coreTemp, setCoreTemp] = useState('')
  const [coldPoint, setColdPoint] = useState<(typeof COLD_POINTS)[number]['value']>('chambre_froide_positive')
  const [coldTemp, setColdTemp] = useState('')
  // Déclaration terrain.
  const [conforme, setConforme] = useState(true)
  const [correctiveAction, setCorrectiveAction] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_slaughter_orders
      .where('status')
      .anyOf('planifie', 'en_cours', 'termine')
      .toArray()
      .then(setOrders)
  }, [])

  const needsOrder = ccp === 'ccp2_evisceration' || ccp === 'ccp3_refroidissement'

  const mesuresValid =
    (ccp === 'ccp1_reception') ||
    (ccp === 'ccp2_evisceration' && carcassesTotal > 0 && carcassesSouillees <= carcassesTotal) ||
    (ccp === 'ccp3_refroidissement' && coreTemp.trim() !== '' && !Number.isNaN(Number(coreTemp))) ||
    (ccp === 'ccp4_chaine_froid' && coldTemp.trim() !== '' && !Number.isNaN(Number(coldTemp)))

  const valid = mesuresValid && (conforme || correctiveAction.trim().length > 0)

  function buildMesures(): Record<string, unknown> {
    switch (ccp) {
      case 'ccp1_reception':
        return { appreciation }
      case 'ccp2_evisceration':
        return { carcasses_total: carcassesTotal, carcasses_souillees: carcassesSouillees }
      case 'ccp3_refroidissement':
        return { temperature_coeur: Number(coreTemp) }
      case 'ccp4_chaine_froid':
        return { point: coldPoint, temperature: Number(coldTemp) }
    }
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!valid) return

    const label = CCPS.find((option) => option.value === ccp)?.label ?? ccp
    await enqueue(
      'ccp_record.create',
      {
        ccp,
        slaughter_order_id: needsOrder && orderId ? Number(orderId) : null,
        equipment_ref: equipmentRef.trim() || null,
        mesures: buildMesures(),
        conforme,
        corrective_action: correctiveAction.trim() || null,
        releve_at: new Date().toISOString(),
      },
      t('Relevé :ccp', { ccp: t(label) }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 900)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Relevé CCP enregistré')}</p>
        <p className="muted">{t('La conformité finale est évaluée par le serveur selon les seuils HACCP.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>{t('📋 Relevé CCP')}</h2>

      <label>{t('Point critique')}</label>
      <div className="chip-row">
        {CCPS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${ccp === option.value ? 'chip-on' : ''}`}
            onClick={() => setCcp(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      {needsOrder && (
        <>
          <label htmlFor="order">{t("Ordre d'abattage — optionnel")}</label>
          <select id="order" value={orderId} onChange={(e) => setOrderId(e.target.value)}>
            <option value="">{t('— Aucun —')}</option>
            {orders.map((order) => (
              <option key={order.id} value={order.id}>
                {order.order_number} · {order.planned_date}
              </option>
            ))}
          </select>
        </>
      )}

      <label htmlFor="equipment">{t('Équipement — optionnel')}</label>
      <input
        id="equipment"
        maxLength={50}
        value={equipmentRef}
        onChange={(e) => setEquipmentRef(e.target.value)}
        placeholder={t('ex. CF-01')}
      />

      {ccp === 'ccp1_reception' && (
        <>
          <label>{t('Appréciation ante-mortem')}</label>
          <div className="chip-row">
            <button
              type="button"
              className={`chip ${appreciation === 'conforme' ? 'chip-on' : ''}`}
              onClick={() => setAppreciation('conforme')}
            >
              {t('Conforme')}
            </button>
            <button
              type="button"
              className={`chip ${appreciation === 'non_conforme' ? 'chip-on' : ''}`}
              onClick={() => setAppreciation('non_conforme')}
            >
              {t('Non conforme')}
            </button>
          </div>
        </>
      )}

      {ccp === 'ccp2_evisceration' && (
        <>
          <NumberStepper label={t('Carcasses contrôlées')} value={carcassesTotal} onChange={setCarcassesTotal} min={0} />
          <NumberStepper label={t('Carcasses souillées')} value={carcassesSouillees} onChange={setCarcassesSouillees} min={0} />
          {carcassesSouillees > carcassesTotal && (
            <p className="error">{t('⚠️ Les souillées ne peuvent pas dépasser le total contrôlé.')}</p>
          )}
        </>
      )}

      {ccp === 'ccp3_refroidissement' && (
        <>
          <label htmlFor="core-temp">{t('Température à cœur (°C)')}</label>
          <input
            id="core-temp"
            type="number"
            inputMode="decimal"
            min={-60}
            max={120}
            step="0.1"
            required
            value={coreTemp}
            onChange={(e) => setCoreTemp(e.target.value)}
            placeholder={t('ex. 3.5')}
          />
        </>
      )}

      {ccp === 'ccp4_chaine_froid' && (
        <>
          <label>{t('Point de contrôle')}</label>
          <div className="chip-row">
            {COLD_POINTS.map((option) => (
              <button
                key={option.value}
                type="button"
                className={`chip ${coldPoint === option.value ? 'chip-on' : ''}`}
                onClick={() => setColdPoint(option.value)}
              >
                {t(option.label)}
              </button>
            ))}
          </div>
          <label htmlFor="cold-temp">{t('Température (°C)')}</label>
          <input
            id="cold-temp"
            type="number"
            inputMode="decimal"
            min={-60}
            max={120}
            step="0.1"
            required
            value={coldTemp}
            onChange={(e) => setColdTemp(e.target.value)}
            placeholder={t('ex. 2.5 ou -18')}
          />
        </>
      )}

      <label>{t('Déclaration terrain')}</label>
      <div className="chip-row">
        <button
          type="button"
          className={`chip ${conforme ? 'chip-on' : ''}`}
          onClick={() => setConforme(true)}
        >
          {t('✅ Conforme')}
        </button>
        <button
          type="button"
          className={`chip ${!conforme ? 'chip-on' : ''}`}
          onClick={() => setConforme(false)}
        >
          {t('⛔ Non conforme')}
        </button>
      </div>

      <label htmlFor="corrective">
        {conforme ? t('Action corrective — optionnel') : t('Action corrective (obligatoire)')}
      </label>
      <textarea
        id="corrective"
        rows={2}
        required={!conforme}
        maxLength={2000}
        value={correctiveAction}
        onChange={(e) => setCorrectiveAction(e.target.value)}
      />
      <p className="muted">{t('La conformité finale est évaluée par le serveur selon les seuils HACCP.')}</p>

      <button type="submit" className="btn-primary" disabled={!valid}>
        {t('Enregistrer le relevé CCP')}
      </button>
    </form>
  )
}
