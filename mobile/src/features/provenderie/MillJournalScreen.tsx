/**
 * Journal de production Provenderie du jour — consultation mobile.
 *
 * Récap (produits / en cours / planifiés, total kg) + liste des ordres de
 * production du jour. Rafraîchi en ligne, dernier instantané en cache (meta)
 * pour rester consultable hors-ligne.
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
import type { MillJournalResponse, MillProductionEntry } from '../../api/types'

const CACHE_KEY = 'mill_journal_today'

const STATUS_CLASS: Record<string, string> = {
  'Terminé': 'pay-paid',
  'En cours': 'pay-partial',
  'Planifié': 'pay-unpaid',
  'Annulé': '',
}

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function MillJournalScreen() {
  const [data, setData] = useState<MillJournalResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [offline, setOffline] = useState(false)
  const [st, setSt] = useState('all')
  const [period, setPeriod] = useState('today')

  useEffect(() => {
    void (async () => {
      setLoading(true)
      const cacheKey = `${CACHE_KEY}_${period}`
      const cached = await getMeta<MillJournalResponse>(cacheKey)
      setData(cached ?? null)
      if (navigator.onLine) {
        try {
          const fresh = await api.provenderieToday(period)
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
  const allProductions: MillProductionEntry[] = data?.productions ?? []
  const productions = useMemo(
    () => (st === 'all' ? allProductions : allProductions.filter((op) => op.status === st)),
    [allProductions, st],
  )

  const countBy = (status: string) => allProductions.filter((op) => op.status === status).length
  // Répartition des kg produits par formule (top 5).
  const byFormula = useMemo(() => {
    const map = new Map<string, number>()
    for (const op of allProductions.filter((o) => o.status === 'Terminé')) {
      const key = op.formula ?? t('Formule')
      map.set(key, (map.get(key) ?? 0) + op.quantity_produced)
    }
    return [...map.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value).slice(0, 5)
  }, [allProductions])
  const chips = [
    { key: 'all', label: t('Tous'), count: allProductions.length },
    { key: 'Terminé', label: t('Terminé'), count: countBy('Terminé') },
    { key: 'En cours', label: t('En cours'), count: countBy('En cours') },
    { key: 'Planifié', label: t('Planifié'), count: countBy('Planifié') },
  ]

  function handleExport() {
    const csv = toCsv(
      [t('OP'), t('Formule'), t('Statut'), t('kg produits')],
      productions.map((op) => [op.batch_number, op.formula ?? '', t(op.status), op.quantity_produced]),
    )
    void exportOrShare(`provenderie_${period}_${dateStamp()}.csv`, csv, t('Production du jour'))
  }

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Production du jour')} 🌾</h2>
        <span className="welcome-sub">
          {new Date().toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
          {offline ? ' · ' + t('hors-ligne (dernier instantané)') : ''}
        </span>
      </div>

      <PeriodSelector period={period} onChange={setPeriod} />
      <ExportButton onExport={handleExport} disabled={productions.length === 0} />

      {summary && (
        <div className="kpi-grid">
          <div className="kpi"><div className="kpi-val">{nf(summary.total_kg)}</div><div className="kpi-lab">{t('kg produits')}</div></div>
          <div className="kpi"><div className="kpi-val">{summary.done}</div><div className="kpi-lab">{t('OP terminées')}</div></div>
          {summary.in_progress > 0 && <div className="kpi kpi--alert"><div className="kpi-val">{summary.in_progress}</div><div className="kpi-lab">{t('En cours')}</div></div>}
          {summary.planned > 0 && <div className="kpi"><div className="kpi-val">{summary.planned}</div><div className="kpi-lab">{t('Planifiées')}</div></div>}
        </div>
      )}

      {period === '7days' && data?.series && <TimeSeriesChart points={data.series} unit="kg" title={t('Production · 7 jours')} />}
      {byFormula.length > 0 && <BarBreakdown items={byFormula} unit="kg" />}
      {allProductions.length > 0 && <FilterChips options={chips} active={st} onChange={setSt} />}

      {loading && !data ? (
        <div className="ok-card ok-muted">{t('Chargement…')}</div>
      ) : productions.length === 0 ? (
        <div className="ok-card">✓ {t('Aucune production aujourd’hui.')}</div>
      ) : (
        productions.map((op) => (
          <div key={op.id} className="task-row">
            <div className="task-row__body">
              <span className="task-title">{op.batch_number} · {op.formula ?? t('Formule')}</span>
              <span className="task-meta">
                {op.started_at ? new Date(op.started_at).toLocaleTimeString(dateLocale(), { hour: '2-digit', minute: '2-digit' }) + ' · ' : ''}
                <span className={`pay-badge ${STATUS_CLASS[op.status] ?? ''}`}>{t(op.status)}</span>
              </span>
            </div>
            <div className="stock-qty">
              <span className="stock-qty__val">{nf(op.quantity_produced)}</span>
              <span className="stock-qty__unit">kg</span>
            </div>
          </div>
        ))
      )}
    </div>
  )
}
