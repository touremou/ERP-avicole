/**
 * Encaissement de créance — le livreur encaisse CHEZ le client, hors réseau.
 * Le reste dû affiché vient du dernier pull (guide de saisie) ; l'autorité
 * reste le serveur : RecordPayment relit le dû SOUS VERROU au push, donc deux
 * encaissements concurrents ne peuvent jamais dépasser le montant dû, et un
 * rejeu de l'opération ne double pas l'encaissement (idempotence par uuid).
 * Contrat : SyncService::paymentCreate (gate commerce.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefClient, RefSale } from '../../api/types'

const METHODS = [
  { value: 'especes', label: '💵 Espèces' },
  { value: 'orange_money', label: '📱 Orange Money' },
  { value: 'virement', label: '🏦 Virement' },
  { value: 'cheque', label: '📝 Chèque' },
] as const

function money(value: number): string {
  return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Math.round(value || 0))
}

export function PaymentScreen() {
  const { saleId } = useParams()
  const navigate = useNavigate()

  const [sales, setSales] = useState<RefSale[]>([])
  const [clients, setClients] = useState<RefClient[]>([])
  const [selected, setSelected] = useState(saleId ?? '')
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<(typeof METHODS)[number]['value']>('especes')
  const [reference, setReference] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    void db.ref_sales.reverse().sortBy('sale_date').then((rows) => setSales(rows.slice(0, 60)))
    void db.ref_clients.toArray().then(setClients)
  }, [])

  const clientName = (id: number | null) =>
    clients.find((c) => c.id === id)?.name ?? t('Client comptoir')

  const sale = useMemo(() => sales.find((s) => s.id === Number(selected)) ?? null, [sales, selected])
  const remaining = sale ? Math.max(0, Number(sale.total_amount) - Number(sale.paid_amount)) : 0
  const value = Number(amount) || 0
  // Garde-fou de saisie : le serveur refuse tout dépassement du reste dû.
  const overpaid = sale !== null && value > remaining + 0.001
  const canSubmit = Boolean(selected) && value > 0 && !overpaid

  // Créance sélectionnée → propose le solde complet (cas le plus fréquent).
  useEffect(() => {
    if (sale && amount === '') setAmount(String(Math.round(remaining)))
  }, [sale, remaining, amount])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit || !sale) return

    await enqueue(
      'payment.create',
      {
        sale_id: sale.id,
        amount: value,
        payment_date: new Date().toISOString().slice(0, 10),
        method,
        reference: reference.trim() || null,
      },
      t('Encaissement :amount — :ref', { amount: money(value), ref: sale.reference }),
    )

    setSaved(true)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Encaissement enregistré')}</p>
        <p className="muted">{t('Le reste dû et le solde client seront recalculés par le serveur au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>💰 {t('Encaisser une créance')}</h2>

      <label htmlFor="sale">{t('Créance à encaisser')}</label>
      <select id="sale" required value={selected} onChange={(e) => { setSelected(e.target.value); setAmount('') }}>
        <option value="">{t('Sélectionner…')}</option>
        {sales.map((s) => (
          <option key={s.id} value={s.id}>
            {s.reference} · {clientName(s.client_id)} · {money(Number(s.total_amount) - Number(s.paid_amount))}
          </option>
        ))}
      </select>
      {sales.length === 0 && (
        <p className="muted">{t('Aucune créance ouverte en local — synchronisez d’abord.')}</p>
      )}

      {sale && (
        <div className="round-row">
          <p className="muted">
            {t('Client')} : <strong>{clientName(sale.client_id)}</strong> · {t('Vente du :date', { date: sale.sale_date })}
          </p>
          <p className="big">{t('Reste dû')} : {money(remaining)}</p>
          {Number(sale.paid_amount) > 0 && (
            <p className="muted">{t('Déjà réglé : :amount sur :total', {
              amount: money(Number(sale.paid_amount)), total: money(Number(sale.total_amount)),
            })}</p>
          )}
        </div>
      )}

      <label htmlFor="amount">{t('Montant encaissé')}</label>
      <input
        id="amount"
        type="number"
        inputMode="decimal"
        min={1}
        step="1"
        required
        value={amount}
        onChange={(e) => setAmount(e.target.value)}
      />
      {overpaid && (
        <p className="error">{t('⚠️ Dépasse le reste dû (:max) — le serveur refuserait l’encaissement.', { max: money(remaining) })}</p>
      )}

      <label>{t('Mode de paiement')}</label>
      <div className="chip-row">
        {METHODS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`chip ${method === option.value ? 'chip-on' : ''}`}
            onClick={() => setMethod(option.value)}
          >
            {t(option.label)}
          </button>
        ))}
      </div>

      {method !== 'especes' && (
        <>
          <label htmlFor="ref">{t('Référence transaction — optionnel')}</label>
          <input id="ref" maxLength={100} value={reference} onChange={(e) => setReference(e.target.value)} placeholder={t('ex. OM-12345')} />
        </>
      )}

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Enregistrer l’encaissement')}
      </button>
    </form>
  )
}
