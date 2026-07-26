/**
 * Contrôle de conservation — se fait AU MAGASIN, balance en main, souvent sans
 * réseau.
 *
 * L'écran est construit autour d'une seule idée : on saisit ce qu'on MESURE. La
 * freinte n'est jamais tapée, elle se déduit de la pesée — un écart saisi
 * directement serait un chiffre « au sentiment », impossible à recouper d'un
 * contrôle à l'autre.
 *
 * Le relevé du COURS DU MARCHÉ fait partie du contrôle : c'est la personne qui
 * se déplace qui connaît le prix du jour, et c'est ce relevé qui rend le
 * prix-cible exploitable — l'écran dit alors « objectif atteint, vendez ».
 *
 * Contrat : SyncService::storedLotCheck (gate logistique.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue, declaredNow } from '../../offline/sync'
import { safeLoad } from '../../offline/safeLoad'
import { t, dateLocale } from '../../i18n'
import type { RefStoredLot } from '../../api/types'

/** Miroir de App\Models\StoredLotCheck::CONDITIONS. */
const CONDITIONS = [
  { value: 'bon', label: '✅ Bon état' },
  { value: 'humide', label: '💧 Reprise d’humidité' },
  { value: 'insectes', label: '🐛 Insectes' },
  { value: 'moisissure', label: '🦠 Moisissure' },
  { value: 'degrade', label: '⚠️ Dégradé' },
] as const

/** Miroir de App\Models\StoredLotCheck::ACTIONS. */
const ACTIONS = [
  { value: 'aucune', label: 'Aucune action' },
  { value: 'sechage', label: 'Séchage complémentaire' },
  { value: 'traitement', label: 'Traitement' },
  { value: 'reconditionne', label: 'Reconditionnement' },
  { value: 'declassement', label: 'Déclassement' },
  { value: 'destruction', label: 'Destruction' },
] as const

/** États qui exigent une décision (miroir CONDITIONS_REQUIRING_ACTION). */
const GRAVE = ['insectes', 'moisissure', 'degrade']

export function StoredLotCheckScreen() {
  const { lotId } = useParams()
  const navigate = useNavigate()

  const [lots, setLots] = useState<RefStoredLot[]>([])
  const [selected, setSelected] = useState(lotId ?? '')
  const [weighed, setWeighed] = useState('')
  const [condition, setCondition] = useState<string>('bon')
  const [action, setAction] = useState<string>('aucune')
  const [marketPrice, setMarketPrice] = useState('')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void safeLoad('conservation:lots', async () => {
      const all = await db.ref_stored_lots.toArray()
      setLots(all.filter((l) => l.status === 'en_stock'))
    })
  }, [])

  const lot = useMemo(
    () => lots.find((l) => l.id === Number(selected)) ?? null,
    [lots, selected],
  )

  // Un état grave impose une décision : on ne laisse pas « aucune action ».
  const needsAction = GRAVE.includes(condition)

  const current = lot ? Number(lot.quantity_current) : 0
  const weighedValue = weighed.trim() !== '' ? Number(weighed) : null

  // Une marchandise conservée ne prend pas de poids : au-delà, c'est une erreur
  // de balance ou un mélange de lots — le serveur refuse aussi.
  const overweight = weighedValue !== null && weighedValue > current + 0.0001

  const shrinkage = weighedValue !== null && !overweight
    ? Math.round((current - weighedValue) * 1000) / 1000
    : null
  const shrinkagePercent = shrinkage !== null && current > 0
    ? Math.round((shrinkage / current) * 1000) / 10
    : null

  // Objectif atteint, d'après le cours que l'opérateur vient de relever.
  const target = lot?.target_unit_price != null ? Number(lot.target_unit_price) : null
  const observed = marketPrice.trim() !== '' ? Number(marketPrice) : null
  const targetReached = target !== null && observed !== null ? observed >= target : null

  // Marge si l'on vend maintenant, freinte déduite : le chiffre qui décide.
  const marginNow = useMemo(() => {
    if (!lot || observed === null || lot.unit_cost == null) return null
    const remaining = weighedValue !== null && !overweight ? weighedValue : current
    return Math.round(remaining * observed - Number(lot.quantity_initial) * Number(lot.unit_cost))
  }, [lot, observed, weighedValue, overweight, current])

  const canSubmit = Boolean(lot) && !overweight && !(needsAction && action === 'aucune')

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!lot || !canSubmit) return

    await enqueue(
      'stored_lot.check',
      {
        stored_lot_id: lot.id,
        // Horodatage DÉCLARÉ : le contrôle compte au jour où il a été fait, pas
        // au jour où le téléphone a retrouvé le réseau.
        checked_at: declaredNow(),
        weighed_quantity: weighedValue,
        condition,
        action_taken: action,
        market_price: observed,
        notes: notes.trim() || null,
      },
      t('Contrôle :label', { label: lot.label }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Contrôle enregistré')}</p>
        <p className="muted">{t('La freinte sera répercutée sur l’inventaire au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>📦 {t('Contrôle de conservation')}</h2>

      <label htmlFor="lot">{t('Lot à contrôler')}</label>
      <select id="lot" required value={selected} onChange={(e) => setSelected(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {lots.map((l) => (
          <option key={l.id} value={l.id}>
            {l.label} · {Number(l.quantity_current).toLocaleString(dateLocale())} {l.unit}
          </option>
        ))}
      </select>
      {lots.length === 0 && (
        <p className="muted">{t('Aucun lot en conservation en local — synchronisez d’abord.')}</p>
      )}

      {lot && (
        <>
          <p className="proof-hint">
            📦 {t('Dernier relevé : :n :unit', {
              n: current.toLocaleString(dateLocale()), unit: lot.unit,
            })}
            {lot.hold_until ? ` · ${t('butoir')} ${new Date(lot.hold_until).toLocaleDateString(dateLocale())}` : ''}
            {target !== null ? ` · ${t('objectif')} ${target.toLocaleString(dateLocale())}/${lot.unit}` : ''}
          </p>

          <label htmlFor="weighed">{t('Pesée du lot (:unit)', { unit: lot.unit })}</label>
          <input
            id="weighed"
            type="number"
            inputMode="decimal"
            min="0"
            step="0.001"
            value={weighed}
            onChange={(e) => setWeighed(e.target.value)}
            placeholder={String(current)}
          />
          {overweight && (
            <p className="error">
              {t('⚠️ Pesée supérieure au stock du lot (:n :unit) : un lot conservé ne prend pas de poids. Vérifiez la balance, ou un mélange de lots.', {
                n: current.toLocaleString(dateLocale()), unit: lot.unit,
              })}
            </p>
          )}
          {shrinkage !== null && shrinkage > 0 && (
            <p className={shrinkagePercent !== null && shrinkagePercent >= 10 ? 'error' : 'muted'}>
              {t('Freinte : :n :unit (:pct %)', {
                n: shrinkage.toLocaleString(dateLocale()), unit: lot.unit, pct: shrinkagePercent ?? 0,
              })}
            </p>
          )}

          <label>{t('État constaté')}</label>
          <div className="chip-row">
            {CONDITIONS.map((option) => (
              <button
                key={option.value}
                type="button"
                className={`chip ${condition === option.value ? 'chip-on' : ''}`}
                onClick={() => setCondition(option.value)}
              >
                {t(option.label)}
              </button>
            ))}
          </div>

          <label htmlFor="action">
            {needsAction ? t('Décision *') : t('Décision — optionnel')}
          </label>
          <select id="action" value={action} onChange={(e) => setAction(e.target.value)}>
            {ACTIONS.map((option) => (
              <option key={option.value} value={option.value}>{t(option.label)}</option>
            ))}
          </select>
          {needsAction && action === 'aucune' && (
            <p className="error">
              {t('⚠️ Un contrôle qui constate un problème sans rien décider ne protège rien : choisissez une décision.')}
            </p>
          )}

          <label htmlFor="market">
            {t('Cours du marché du jour (par :unit)', { unit: lot.unit })}
          </label>
          <input
            id="market"
            type="number"
            inputMode="numeric"
            min="0"
            step="100"
            value={marketPrice}
            onChange={(e) => setMarketPrice(e.target.value)}
          />
          {targetReached === true && (
            <p className="success">
              {t('✓ Prix-cible atteint — c’est le moment de vendre.')}
            </p>
          )}
          {targetReached === false && target !== null && observed !== null && (
            <p className="muted">
              {t('Encore :n en dessous de l’objectif.', {
                n: (target - observed).toLocaleString(dateLocale()),
              })}
            </p>
          )}
          {marginNow !== null && (
            <p className={marginNow >= 0 ? 'muted' : 'error'}>
              {t('Si je vends maintenant : :n GNF (freinte déduite)', {
                n: marginNow.toLocaleString(dateLocale()),
              })}
            </p>
          )}

          <label htmlFor="notes">{t('Observations — optionnel')}</label>
          <textarea id="notes" rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </>
      )}

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer le contrôle')}
      </button>
    </form>
  )
}
