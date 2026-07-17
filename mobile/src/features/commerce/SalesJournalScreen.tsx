/**
 * Journal des ventes du jour (Commerce) — consultation mobile.
 *
 * Récapitulatif (CA, encaissé, dû) + liste des ventes du jour de la ferme.
 * Vue en ligne rafraîchie à l'ouverture ; dernier instantané mis en cache
 * (meta) pour rester consultable hors-ligne, à l'image des autres écrans.
 */
import { useEffect, useMemo, useState } from 'react'
import { api } from '../../api/client'
import { getMeta, setMeta } from '../../offline/db'
import { t, dateLocale } from '../../i18n'
import { FilterChips } from '../../ui/FilterChips'
import { BarBreakdown } from '../../ui/BarBreakdown'
import type { SalesJournalResponse, SaleJournalEntry } from '../../api/types'

const CACHE_KEY = 'sales_journal_today'

const PAYMENT_CLASS: Record<string, string> = {
  impaye: 'pay-unpaid',
  partiel: 'pay-partial',
  solde: 'pay-paid',
}

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function SalesJournalScreen() {
  const [data, setData] = useState<SalesJournalResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [offline, setOffline] = useState(false)
  const [pay, setPay] = useState('all')

  useEffect(() => {
    void (async () => {
      // 1) Instantané en cache (affichage immédiat, y compris hors-ligne).
      const cached = await getMeta<SalesJournalResponse>(CACHE_KEY)
      if (cached) setData(cached)

      // 2) Rafraîchissement en ligne.
      if (navigator.onLine) {
        try {
          const fresh = await api.salesToday()
          setData(fresh)
          await setMeta(CACHE_KEY, fresh)
          setOffline(false)
        } catch {
          setOffline(true)
        }
      } else {
        setOffline(true)
      }
      setLoading(false)
    })()
  }, [])

  const summary = data?.summary
  const allSales: SaleJournalEntry[] = data?.sales ?? []
  const sales = useMemo(
    () => (pay === 'all' ? allSales : allSales.filter((s) => s.payment_status === pay)),
    [allSales, pay],
  )

  // Répartition du CA par statut de paiement (graphique).
  const breakdown = useMemo(
    () => [
      { label: t('Soldé'), value: allSales.filter((s) => s.payment_status === 'solde').reduce((n, s) => n + s.total_amount, 0), color: '#16a34a' },
      { label: t('Partiel'), value: allSales.filter((s) => s.payment_status === 'partiel').reduce((n, s) => n + s.total_amount, 0), color: '#d97706' },
      { label: t('Impayé'), value: allSales.filter((s) => s.payment_status === 'impaye').reduce((n, s) => n + s.total_amount, 0), color: '#dc2626' },
    ],
    [allSales],
  )

  const chips = [
    { key: 'all', label: t('Tous'), count: allSales.length },
    { key: 'solde', label: t('Soldé'), count: allSales.filter((s) => s.payment_status === 'solde').length },
    { key: 'partiel', label: t('Partiel'), count: allSales.filter((s) => s.payment_status === 'partiel').length },
    { key: 'impaye', label: t('Impayé'), count: allSales.filter((s) => s.payment_status === 'impaye').length },
  ]

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Ventes du jour')} 🧾</h2>
        <span className="welcome-sub">
          {new Date().toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
          {offline ? ' · ' + t('hors-ligne (dernier instantané)') : ''}
        </span>
      </div>

      {summary && (
        <div className="kpi-grid">
          <div className="kpi"><div className="kpi-val">{summary.count}</div><div className="kpi-lab">{t('Ventes')}</div></div>
          <div className="kpi"><div className="kpi-val">{nf(summary.total)}</div><div className="kpi-lab">{t('CA du jour')}</div></div>
          <div className="kpi"><div className="kpi-val">{nf(summary.paid)}</div><div className="kpi-lab">{t('Encaissé')}</div></div>
          {summary.remaining > 0 && (
            <div className="kpi kpi--alert"><div className="kpi-val">{nf(summary.remaining)}</div><div className="kpi-lab">{t('Reste dû')}</div></div>
          )}
        </div>
      )}

      {allSales.length > 0 && <BarBreakdown items={breakdown} />}
      {allSales.length > 0 && <FilterChips options={chips} active={pay} onChange={setPay} />}

      {loading && !data ? (
        <div className="ok-card ok-muted">{t('Chargement…')}</div>
      ) : sales.length === 0 ? (
        <div className="ok-card">✓ {t('Aucune vente enregistrée aujourd’hui.')}</div>
      ) : (
        sales.map((sale) => (
          <div key={sale.id} className="task-row">
            <div className="task-row__body">
              <span className="task-title">{sale.reference} · {sale.client_name ?? t('Client comptoir')}</span>
              <span className="task-meta">
                {sale.created_at ? new Date(sale.created_at).toLocaleTimeString(dateLocale(), { hour: '2-digit', minute: '2-digit' }) + ' · ' : ''}
                {t(sale.status)}
                <span className={`pay-badge ${PAYMENT_CLASS[sale.payment_status] ?? ''}`}>
                  {sale.payment_status === 'solde' ? t('Soldé') : sale.payment_status === 'partiel' ? t('Partiel') : t('Impayé')}
                </span>
              </span>
            </div>
            <div className="stock-qty">
              <span className="stock-qty__val">{nf(sale.total_amount)}</span>
            </div>
          </div>
        ))
      )}
    </div>
  )
}
