/**
 * BatchHistory — bloc de consultation enrichie d'un lot : indicateurs de
 * performance (GMQ, mortalité, effectif) + courbe de poids (sparkline SVG) +
 * historique des derniers pointages. Rafraîchi en ligne, dernier instantané
 * mis en cache (meta) pour rester consultable hors-ligne.
 */
import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { getMeta, setMeta } from '../../offline/db'
import { t } from '../../i18n'
import type { BatchHistoryResponse, BatchCheck } from '../../api/types'

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

/** Sparkline SVG des poids (kg) au fil des pointages. */
function WeightSparkline({ checks }: { checks: BatchCheck[] }) {
  const points = checks.filter((c) => c.weight != null && c.weight > 0) as (BatchCheck & { weight: number })[]
  if (points.length < 2) return null

  const w = 280
  const h = 64
  const pad = 6
  const weights = points.map((p) => p.weight)
  const min = Math.min(...weights)
  const max = Math.max(...weights)
  const span = max - min || 1
  const stepX = (w - pad * 2) / (points.length - 1)
  const coords = points.map((p, i) => {
    const x = pad + i * stepX
    const y = h - pad - ((p.weight - min) / span) * (h - pad * 2)
    return `${x.toFixed(1)},${y.toFixed(1)}`
  })

  return (
    <svg viewBox={`0 0 ${w} ${h}`} className="sparkline" role="img" aria-label={t('Courbe de poids')}>
      <polyline points={coords.join(' ')} fill="none" stroke="#2563eb" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
      {coords.map((c, i) => {
        const [x, y] = c.split(',')
        return <circle key={i} cx={x} cy={y} r="2.5" fill="#2563eb" />
      })}
    </svg>
  )
}

export function BatchHistory({ batchId }: { batchId: number }) {
  const [data, setData] = useState<BatchHistoryResponse | null>(null)
  const [offline, setOffline] = useState(false)
  const cacheKey = `batch_history_${batchId}`

  useEffect(() => {
    void (async () => {
      const cached = await getMeta<BatchHistoryResponse>(cacheKey)
      if (cached) setData(cached)
      if (navigator.onLine) {
        try {
          const fresh = await api.batchHistory(batchId)
          setData(fresh)
          await setMeta(cacheKey, fresh)
          setOffline(false)
        } catch {
          setOffline(true)
        }
      } else {
        setOffline(true)
      }
    })()
  }, [batchId, cacheKey])

  if (!data) return null
  const b = data.batch
  const recent = [...data.checks].reverse().slice(0, 8)

  return (
    <section>
      <div className="section-head">
        <h3>{t('Performance')}</h3>
        {offline && <span className="section-count">{t('hors-ligne')}</span>}
      </div>

      <div className="kpi-grid">
        {b.is_gmq_tracked && b.gmq != null && (
          <div className="kpi"><div className="kpi-val">{nf(b.gmq)}</div><div className="kpi-lab">{t('GMQ g/j')}</div></div>
        )}
        {b.latest_weight != null && (
          <div className="kpi"><div className="kpi-val">{b.latest_weight}</div><div className="kpi-lab">{t('Poids kg')}</div></div>
        )}
        <div className="kpi"><div className="kpi-val">{nf(b.total_mortality)}</div><div className="kpi-lab">{t('Morts cumul')}</div></div>
        <div className={`kpi ${b.mortality_rate > 5 ? 'kpi--alert' : ''}`}><div className="kpi-val">{b.mortality_rate}%</div><div className="kpi-lab">{t('Taux mort.')}</div></div>
      </div>

      {data.checks.some((c) => c.weight != null) && (
        <div className="card-plain">
          <span className="task-meta">{t('Courbe de poids (kg)')}</span>
          <WeightSparkline checks={data.checks} />
        </div>
      )}

      {recent.length > 0 && (
        <>
          <div className="section-head"><h3>{t('Derniers pointages')}</h3><span className="section-count">{data.checks.length}</span></div>
          {recent.map((check, i) => (
            <div key={i} className="task-row">
              <div className="task-row__body">
                <span className="task-title">{check.date}</span>
                <span className="task-meta">
                  {check.mortality > 0 ? t(':n mort(s)', { n: check.mortality }) : t('0 mort')}
                  {check.feed != null ? ' · ' + t(':n kg alim.', { n: check.feed }) : ''}
                </span>
              </div>
              {check.weight != null && (
                <div className="stock-qty"><span className="stock-qty__val">{check.weight}</span><span className="stock-qty__unit">kg</span></div>
              )}
            </div>
          ))}
        </>
      )}
    </section>
  )
}
