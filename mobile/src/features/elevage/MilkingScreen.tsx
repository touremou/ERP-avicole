/**
 * Traite — collecte de lait matin/soir saisie À LA CHÈVRERIE. Le total est
 * maintenu serveur (matin + soir) et unit_price est un snapshot du cours du
 * jour, le lait étant volatil.
 * Contrat : SyncService::milkProductionCreate (gate production.C).
 */
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { lastPayloadOf } from '../../offline/prefill'
import { NumberStepper } from '../../ui/NumberStepper'
import { t } from '../../i18n'
import type { RefBatch } from '../../api/types'

export function MilkingScreen() {
  const { batchId } = useParams()
  const navigate = useNavigate()

  const [batches, setBatches] = useState<RefBatch[]>([])
  const [selected, setSelected] = useState(batchId ?? '')
  const [morning, setMorning] = useState('')
  const [evening, setEvening] = useState('')
  const [females, setFemales] = useState(0)
  const [unitPrice, setUnitPrice] = useState('')
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_batches.where('status').equals('Actif').toArray().then(setBatches)
  }, [])

  // Anti-corvée : le cours du lait et l'effectif en lactation bougent peu d'un
  // jour à l'autre → rappelés de la dernière collecte.
  useEffect(() => {
    void lastPayloadOf('milk_production.create', () => true).then((last) => {
      if (!last) return
      if (last.unit_price) setUnitPrice(String(last.unit_price))
      if (last.milking_females) setFemales(Number(last.milking_females))
    })
  }, [])

  const total = (Number(morning) || 0) + (Number(evening) || 0)
  const value = total * (Number(unitPrice) || 0)
  const perFemale = females > 0 ? Math.round((total / females) * 100) / 100 : null
  const canSubmit = Boolean(selected) && total > 0

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    const batch = batches.find((b) => b.id === Number(selected))
    await enqueue(
      'milk_production.create',
      {
        batch_id: Number(selected),
        production_date: new Date().toISOString().slice(0, 10),
        morning_liters: Number(morning) || 0,
        evening_liters: Number(evening) || 0,
        unit_price: Number(unitPrice) || null,
        milking_females: females || null,
        notes: notes.trim() || null,
      },
      t('Traite :batch — :n L', { batch: batch?.code ?? selected, n: total }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Traite enregistrée')}</p>
        <p className="muted">{t('Le total et la valorisation seront consolidés au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🥛 {t('Traite du jour')}</h2>

      <label htmlFor="batch">{t('Lot en lactation')}</label>
      <select id="batch" required value={selected} onChange={(e) => setSelected(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {batches.map((b) => (
          <option key={b.id} value={b.id}>{b.code} · {b.current_quantity} {t('têtes')}</option>
        ))}
      </select>
      {batches.length === 0 && (
        <p className="muted">{t('Aucun lot actif en local — synchronisez d’abord.')}</p>
      )}

      <label htmlFor="morning">{t('Traite du matin (litres)')}</label>
      <input
        id="morning"
        type="number"
        inputMode="decimal"
        min={0}
        step="0.1"
        value={morning}
        onChange={(e) => setMorning(e.target.value)}
        placeholder="0.0"
      />

      <label htmlFor="evening">{t('Traite du soir (litres)')}</label>
      <input
        id="evening"
        type="number"
        inputMode="decimal"
        min={0}
        step="0.1"
        value={evening}
        onChange={(e) => setEvening(e.target.value)}
        placeholder="0.0"
      />

      {total > 0 && (
        <p className="big">{t('Total : :n L', { n: Math.round(total * 100) / 100 })}</p>
      )}

      <NumberStepper label={t('Femelles en lactation')} value={females} onChange={setFemales} min={0} />
      {perFemale !== null && total > 0 && (
        <p className="muted">{t('Soit :n L par femelle', { n: perFemale })}</p>
      )}

      <label htmlFor="price">{t('Prix du litre (GNF) — optionnel')}</label>
      <input
        id="price"
        type="number"
        inputMode="decimal"
        min={0}
        step="100"
        value={unitPrice}
        onChange={(e) => setUnitPrice(e.target.value)}
      />
      {value > 0 && (
        <p className="muted">{t('Valorisation : :amount GNF', { amount: Math.round(value).toLocaleString('fr-FR') })}</p>
      )}

      <label htmlFor="notes">{t('Observations — optionnel')}</label>
      <textarea id="notes" rows={2} maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer la traite')}
      </button>
    </form>
  )
}
