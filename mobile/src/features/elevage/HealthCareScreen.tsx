/**
 * Soin / vaccination — l'intervention sanitaire se saisit AU BÂTIMENT, au
 * moment de l'administration (le carnet papier recopié le soir est la
 * première source d'erreur sur les délais d'attente).
 *
 * Le DÉLAI D'ATTENTE saisi ici verrouille l'abattage du lot jusqu'à son
 * échéance côté serveur (levée automatique à la date, aucune décision à
 * prendre). Contrat : SyncService::healthCheckCreate (gate elevage.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { VoiceDictation } from '../../ui/VoiceDictation'
import { t } from '../../i18n'
import type { RefBatch } from '../../api/types'

/** Miroir de App\Models\HealthCheck (types validés serveur). */
const TYPES = [
  { value: 'Vaccin', label: '💉 Vaccin' },
  { value: 'Traitement', label: '💊 Traitement' },
  { value: 'Vitamine', label: '🧪 Vitamine' },
  { value: 'Désinfection', label: '🧽 Désinfection' },
] as const

const MODES = ['Eau de boisson', 'Injection', 'Pulvérisation', 'Aliment', 'Oculaire'] as const

export function HealthCareScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batches, setBatches] = useState<RefBatch[]>([])
  const [selectedBatch, setSelectedBatch] = useState(batchId ?? '')
  const [type, setType] = useState<(typeof TYPES)[number]['value']>('Vaccin')
  const [productName, setProductName] = useState('')
  const [dosage, setDosage] = useState('')
  const [mode, setMode] = useState<string>(MODES[0])
  const [withdrawalDays, setWithdrawalDays] = useState('')
  const [productBatch, setProductBatch] = useState('')
  const [expiry, setExpiry] = useState('')
  const [observations, setObservations] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_batches.where('status').equals('Actif').toArray().then(setBatches)
  }, [])

  // Anti-corvée : un même vaccin se refait sur d'autres bâtiments dans la
  // journée → rappelle produit/dose/mode/délai du dernier soin du même type.
  useEffect(() => {
    void lastPayloadOf('health_check.create', (p) => p.type === type).then((last) => {
      if (!last) return
      if (typeof last.product_name === 'string') setProductName(last.product_name)
      setDosage(typeof last.dosage === 'string' ? last.dosage : '')
      if (typeof last.mode_administration === 'string') setMode(last.mode_administration)
      setWithdrawalDays(last.withdrawal_days ? String(last.withdrawal_days) : '')
    })
  }, [type])

  const today = new Date().toISOString().slice(0, 10)
  // Miroir du garde-fou serveur : produit périmé → administration interdite.
  const expired = expiry !== '' && expiry < today
  const days = Number(withdrawalDays) || 0
  const withdrawalUntil = days > 0
    ? new Date(Date.now() + days * 86400000).toISOString().slice(0, 10)
    : null
  const canSubmit = Boolean(selectedBatch) && productName.trim() !== '' && !expired

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    const batch = batches.find((b) => b.id === Number(selectedBatch))
    await enqueue(
      'health_check.create',
      {
        batch_id: Number(selectedBatch),
        intervention_date: today,
        type,
        product_name: productName.trim(),
        dosage: dosage.trim() || null,
        mode_administration: mode,
        withdrawal_days: days > 0 ? days : null,
        batch_number: productBatch.trim() || null,
        expiry_date: expiry || null,
        observations: observations.trim() || null,
      },
      t(':type :product — lot :batch', {
        type: t(type), product: productName.trim(), batch: batch?.code ?? selectedBatch,
      }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Soin enregistré')}</p>
        {withdrawalUntil ? (
          <p className="muted">
            {t('Délai d’attente jusqu’au :date — l’abattage du lot sera refusé avant cette date.', { date: withdrawalUntil })}
          </p>
        ) : (
          <p className="muted">{t('Intervention tracée au carnet sanitaire du lot.')}</p>
        )}
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>💉 {t('Soin / vaccination')}</h2>

      <label htmlFor="batch">{t('Lot traité')}</label>
      <select id="batch" required value={selectedBatch} onChange={(e) => setSelectedBatch(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {batches.map((b) => (
          <option key={b.id} value={b.id}>{b.code} · {b.current_quantity} {t('sujets')}</option>
        ))}
      </select>
      {batches.length === 0 && (
        <p className="muted">{t('Aucun lot actif en local — synchronisez d’abord.')}</p>
      )}

      <label>{t('Type d’intervention')}</label>
      <div className="chip-row">
        {TYPES.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${type === option.value ? 'chip-on' : ''}`}
            onClick={() => setType(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      <label htmlFor="product">{t('Produit administré')}</label>
      <input
        id="product"
        required
        maxLength={255}
        value={productName}
        onChange={(e) => setProductName(e.target.value)}
        placeholder={t('ex. Gumboro NDV')}
      />

      <label htmlFor="dosage">{t('Dosage — optionnel')}</label>
      <input id="dosage" maxLength={100} value={dosage} onChange={(e) => setDosage(e.target.value)} placeholder={t('ex. 1 ml / sujet')} />

      <label>{t('Mode d’administration')}</label>
      <div className="chip-row">
        {MODES.map((option) => (
          <button
            key={option}
            type="button"
            className={`chip ${mode === option ? 'chip-on' : ''}`}
            onClick={() => setMode(option)}
          >
            {t(option)}
          </button>
        ))}
      </div>

      {/* CŒUR RÉGLEMENTAIRE : le délai d'attente de la notice. */}
      <label htmlFor="withdrawal">{t('Délai d’attente (jours) — notice du produit')}</label>
      <input
        id="withdrawal"
        type="number"
        inputMode="numeric"
        min={0}
        max={365}
        value={withdrawalDays}
        onChange={(e) => setWithdrawalDays(e.target.value)}
        placeholder={t('0 = aucun délai')}
      />
      {withdrawalUntil && (
        <p className="proof-hint">
          🔒 {t('Abattage bloqué jusqu’au :date (:n j)', { date: withdrawalUntil, n: days })}
        </p>
      )}

      <label htmlFor="pbatch">{t('N° de lot fabricant — optionnel')}</label>
      <input id="pbatch" maxLength={100} value={productBatch} onChange={(e) => setProductBatch(e.target.value)} />

      <label htmlFor="expiry">{t('Péremption du produit — optionnel')}</label>
      <input id="expiry" type="date" value={expiry} onChange={(e) => setExpiry(e.target.value)} />
      {expired && (
        <p className="error">{t('⚠️ Produit périmé — administration interdite.')}</p>
      )}

      <label htmlFor="obs">{t('Observations — optionnel')}</label>
      <textarea id="obs" rows={2} maxLength={2000} value={observations} onChange={(e) => setObservations(e.target.value)} />
      <div className="chip-row">
        <VoiceDictation onText={(text) => setObservations((prev) => (prev ? prev + ' ' : '') + text)} />
      </div>

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer le soin')}
      </button>
    </form>
  )
}
