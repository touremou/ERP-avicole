/**
 * Réception d'aliment au portail — le camion arrive à la ferme, pas au bureau.
 * Le serveur crédite le magasin AU COÛT RÉEL (le CMP de l'article reflète le
 * prix payé), crée la facture fournisseur et, selon le mode, le règlement ou
 * la dette. Contrat : SyncService::feedPurchaseCreate, idempotent par uuid.
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { t } from '../../i18n'
import type { RefBatch, RefProvider } from '../../api/types'

const UNITS = ['Sac', 'KG', 'Litre', 'Unité', 'Boite'] as const

function money(value: number): string {
  return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Math.round(value || 0))
}

export function FeedReceptionScreen() {
  const navigate = useNavigate()

  const [batches, setBatches] = useState<RefBatch[]>([])
  const [providers, setProviders] = useState<RefProvider[]>([])
  const [batchId, setBatchId] = useState('')
  const [feedType, setFeedType] = useState('')
  const [quantity, setQuantity] = useState('')
  const [unit, setUnit] = useState<(typeof UNITS)[number]>('Sac')
  const [bagWeight, setBagWeight] = useState('50')
  const [total, setTotal] = useState('')
  const [supplier, setSupplier] = useState('')
  const [paymentMode, setPaymentMode] = useState<'comptant' | 'credit'>('comptant')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_batches.where('status').equals('Actif').toArray().then(setBatches)
    void db.ref_providers.toArray().then(setProviders)
  }, [])

  // Anti-corvée : la ferme rachète le même aliment au même fournisseur —
  // rappelle type/unité/poids de sac/fournisseur de la dernière réception.
  useEffect(() => {
    void lastPayloadOf('feed_purchase.create', () => true).then((last) => {
      if (!last) return
      if (typeof last.feed_type === 'string') setFeedType(last.feed_type)
      if (typeof last.unit === 'string') setUnit(last.unit as (typeof UNITS)[number])
      if (typeof last.supplier === 'string') setSupplier(last.supplier)
      const meta = last.metadata as Record<string, unknown> | undefined
      if (meta && meta.bag_weight) setBagWeight(String(meta.bag_weight))
    })
  }, [])

  const qty = Number(quantity) || 0
  const amount = Number(total) || 0
  const weight = Number(bagWeight) || 0
  // Quantité pivot en KG : c'est elle qui porte le coût unitaire du magasin.
  const pivotKg = unit === 'Sac' ? qty * weight : qty
  const costPerKg = pivotKg > 0 ? amount / pivotKg : 0
  const canSubmit = Boolean(batchId) && feedType.trim() !== '' && qty > 0 && amount >= 0

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    await enqueue(
      'feed_purchase.create',
      {
        batch_id: Number(batchId),
        purchase_date: new Date().toISOString().slice(0, 10),
        feed_type: feedType.trim(),
        quantity: qty,
        // Le serveur attend le montant TOTAL payé dans unit_price (contrat web).
        unit_price: amount,
        unit,
        supplier: supplier.trim() || null,
        payment_mode: paymentMode,
        metadata: unit === 'Sac' ? { bag_weight: weight } : {},
      },
      t('Réception :qty :unit de :feed', { qty, unit, feed: feedType.trim() }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Réception enregistrée')}</p>
        <p className="muted">{t('Le magasin sera crédité au coût réel et la facture fournisseur créée au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🚚 {t('Réception d’aliment')}</h2>

      <label htmlFor="batch">{t('Lot destinataire')}</label>
      <select id="batch" required value={batchId} onChange={(e) => setBatchId(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {batches.map((b) => (
          <option key={b.id} value={b.id}>{b.code}</option>
        ))}
      </select>
      {batches.length === 0 && (
        <p className="muted">{t('Aucun lot actif en local — synchronisez d’abord.')}</p>
      )}

      <label htmlFor="feed">{t('Type d’aliment')}</label>
      <input id="feed" required maxLength={255} value={feedType} onChange={(e) => setFeedType(e.target.value)} placeholder={t('ex. Démarrage chair')} />

      <label>{t('Unité')}</label>
      <div className="chip-row">
        {UNITS.map((option) => (
          <button
            key={option}
            type="button"
            className={`chip ${unit === option ? 'chip-on' : ''}`}
            onClick={() => setUnit(option)}
          >
            {t(option)}
          </button>
        ))}
      </div>

      <label htmlFor="qty">{t('Quantité reçue')}</label>
      <input
        id="qty"
        type="number"
        inputMode="decimal"
        min={0.001}
        step="0.001"
        required
        value={quantity}
        onChange={(e) => setQuantity(e.target.value)}
      />

      {unit === 'Sac' && (
        <>
          <label htmlFor="bag">{t('Poids du sac (kg)')}</label>
          <input id="bag" type="number" inputMode="decimal" min={1} step="0.1" value={bagWeight} onChange={(e) => setBagWeight(e.target.value)} />
        </>
      )}

      <label htmlFor="total">{t('Montant total payé')}</label>
      <input
        id="total"
        type="number"
        inputMode="decimal"
        min={0}
        step="1"
        required
        value={total}
        onChange={(e) => setTotal(e.target.value)}
      />
      {costPerKg > 0 && (
        <p className="muted">{t('Soit :cost /kg — coût porté au magasin.', { cost: money(costPerKg) })}</p>
      )}

      <label htmlFor="supplier">{t('Fournisseur — optionnel')}</label>
      <input id="supplier" maxLength={255} value={supplier} onChange={(e) => setSupplier(e.target.value)} list="providers" placeholder={t('ex. Provenderie Kindia')} />
      <datalist id="providers">
        {providers.map((p) => (
          <option key={p.id} value={p.name} />
        ))}
      </datalist>

      <label>{t('Règlement')}</label>
      <div className="chip-row">
        <button type="button" className={`chip ${paymentMode === 'comptant' ? 'chip-on' : ''}`} onClick={() => setPaymentMode('comptant')}>
          {t('💵 Payé comptant')}
        </button>
        <button type="button" className={`chip ${paymentMode === 'credit' ? 'chip-on' : ''}`} onClick={() => setPaymentMode('credit')}>
          {t('📆 À crédit (dette)')}
        </button>
      </div>

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer la réception')}
      </button>
    </form>
  )
}
