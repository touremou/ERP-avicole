/**
 * Journal d'abattage du jour (Abattoir) — consultation mobile.
 *
 * Récap (abattus / prévus / bloqués, sujets, poids vif) + liste des ordres du
 * jour. Rafraîchi en ligne, dernier instantané en cache (meta) pour rester
 * consultable hors-ligne.
 */
import { useEffect, useMemo, useState } from 'react'
import { api } from '../../api/client'
import { getMeta, setMeta } from '../../offline/db'
import { t, dateLocale } from '../../i18n'
import { FilterChips } from '../../ui/FilterChips'
import { BarBreakdown } from '../../ui/BarBreakdown'
import { TimeSeriesChart } from '../../ui/TimeSeriesChart'
import { PeriodSelector } from '../../ui/PeriodSelector'
import { ExportButton } from '../../ui/ExportButton'
import { toCsv, exportOrShare, dateStamp } from '../../ui/exportShare'
import type { SlaughterJournalResponse, SlaughterOrderEntry } from '../../api/types'

const CACHE_KEY = 'slaughter_journal_today'

const STATUS_CLASS: Record<string, string> = {
  termine: 'pay-paid',
  en_cours: 'pay-partial',
  planifie: 'pay-unpaid',
  bloque: 'pay-unpaid',
}

const STATUS_LABEL: Record<string, string> = {
  termine: 'Terminé',
  en_cours: 'En cours',
  planifie: 'Planifié',
  bloque: 'Bloqué',
  annule: 'Annulé',
}

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function SlaughterJournalScreen() {
  const [data, setData] = useState<SlaughterJournalResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [offline, setOffline] = useState(false)
  const [st, setSt] = useState('all')
  const [period, setPeriod] = useState('today')

  useEffect(() => {
    void (async () => {
      setLoading(true)
      const cacheKey = `${CACHE_KEY}_${period}`
      const cached = await getMeta<SlaughterJournalResponse>(cacheKey)
      setData(cached ?? null)
      if (navigator.onLine) {
        try {
          const fresh = await api.abattoirToday(period)
          setData(fresh)
          await setMeta(cacheKey, fresh)
          setOffline(false)
        } catch {
          setOffline(true)
        }
      } else {
        setOffline(true)
      }
      setLoading(false)
    })()
  }, [period])

  const summary = data?.summary
  const allOrders: SlaughterOrderEntry[] = data?.orders ?? []
  const orders = useMemo(
    () => (st === 'all' ? allOrders : allOrders.filter((o) => o.status === st)),
    [allOrders, st],
  )

  const countBy = (status: string) => allOrders.filter((o) => o.status === status).length
  const breakdown = [
    { label: t('Terminé'), value: countBy('termine'), color: '#16a34a' },
    { label: t('Planifié'), value: countBy('planifie'), color: '#dc2626' },
    { label: t('En cours'), value: countBy('en_cours'), color: '#d97706' },
    { label: t('Bloqué'), value: countBy('bloque'), color: '#7c3aed' },
  ]
  const chips = [
    { key: 'all', label: t('Tous'), count: allOrders.length },
    { key: 'termine', label: t('Terminé'), count: countBy('termine') },
    { key: 'planifie', label: t('Planifié'), count: countBy('planifie') },
    { key: 'bloque', label: t('Bloqué'), count: countBy('bloque') },
  ]

  function handleExport() {
    const csv = toCsv(
      [t('Ordre'), t('Lot'), t('Client'), t('Statut'), t('prévus'), t('abattus')],
      orders.map((o) => [o.order_number, o.batch ?? '', o.client ?? '', t(STATUS_LABEL[o.status] ?? o.status), o.planned_quantity, o.actual_quantity ?? '']),
    )
    void exportOrShare(`abattoir_${period}_${dateStamp()}.csv`, csv, t('Abattage du jour'))
  }

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Abattage du jour')} 🔪</h2>
        <span className="welcome-sub">
          {new Date().toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
          {offline ? ' · ' + t('hors-ligne (dernier instantané)') : ''}
        </span>
      </div>

      <PeriodSelector period={period} onChange={setPeriod} />
      <ExportButton onExport={handleExport} disabled={orders.length === 0} />

      {summary && (
        <div className="kpi-grid">
          <div className="kpi"><div className="kpi-val">{nf(summary.slaughtered)}</div><div className="kpi-lab">{t('Sujets abattus')}</div></div>
          <div className="kpi"><div className="kpi-val">{nf(summary.live_weight_kg)}</div><div className="kpi-lab">{t('Poids vif kg')}</div></div>
          <div className="kpi"><div className="kpi-val">{summary.done}/{summary.total}</div><div className="kpi-lab">{t('OP terminées')}</div></div>
          {summary.blocked > 0 && <div className="kpi kpi--alert"><div className="kpi-val">{summary.blocked}</div><div className="kpi-lab">{t('Bloqués')}</div></div>}
        </div>
      )}

      {period === '7days' && data?.series && <TimeSeriesChart points={data.series} unit={t('sujets')} title={t('Abattus · 7 jours')} />}
      {allOrders.length > 0 && <BarBreakdown items={breakdown} />}
      {allOrders.length > 0 && <FilterChips options={chips} active={st} onChange={setSt} />}

      {loading && !data ? (
        <div className="ok-card ok-muted">{t('Chargement…')}</div>
      ) : orders.length === 0 ? (
        <div className="ok-card">✓ {t('Aucun abattage prévu aujourd’hui.')}</div>
      ) : (
        orders.map((order) => (
          <div key={order.id} className="task-row">
            <div className="task-row__body">
              <span className="task-title">{order.order_number}{order.batch ? ' · ' + order.batch : ''}</span>
              <span className="task-meta">
                {order.client ? order.client + ' · ' : ''}
                <span className={`pay-badge ${STATUS_CLASS[order.status] ?? ''}`}>{t(STATUS_LABEL[order.status] ?? order.status)}</span>
              </span>
            </div>
            <div className="stock-qty">
              <span className="stock-qty__val">{order.actual_quantity ?? order.planned_quantity}</span>
              <span className="stock-qty__unit">{order.actual_quantity != null ? t('abattus') : t('prévus')}</span>
            </div>
          </div>
        ))
      )}
    </div>
  )
}
