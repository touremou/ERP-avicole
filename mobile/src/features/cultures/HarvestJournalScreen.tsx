/**
 * Journal des récoltes du jour (Cultures) — consultation mobile.
 *
 * Récap (nombre, poids net cumulé) + liste des récoltes du jour. Rafraîchi en
 * ligne, dernier instantané en cache (meta) pour rester consultable hors-ligne.
 */
import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { getMeta, setMeta } from '../../offline/db'
import { t, dateLocale } from '../../i18n'
import type { HarvestJournalResponse, HarvestEntry } from '../../api/types'

const CACHE_KEY = 'harvest_journal_today'

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function HarvestJournalScreen() {
  const [data, setData] = useState<HarvestJournalResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [offline, setOffline] = useState(false)

  useEffect(() => {
    void (async () => {
      const cached = await getMeta<HarvestJournalResponse>(CACHE_KEY)
      if (cached) setData(cached)
      if (navigator.onLine) {
        try {
          const fresh = await api.culturesToday()
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
  const harvests: HarvestEntry[] = data?.harvests ?? []

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Récoltes du jour')} 🌱</h2>
        <span className="welcome-sub">
          {new Date().toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
          {offline ? ' · ' + t('hors-ligne (dernier instantané)') : ''}
        </span>
      </div>

      {summary && (
        <div className="kpi-grid">
          <div className="kpi"><div className="kpi-val">{nf(summary.total_weight_kg)}</div><div className="kpi-lab">{t('kg récoltés')}</div></div>
          <div className="kpi"><div className="kpi-val">{summary.count}</div><div className="kpi-lab">{t('Récoltes')}</div></div>
        </div>
      )}

      {loading && !data ? (
        <div className="ok-card ok-muted">{t('Chargement…')}</div>
      ) : harvests.length === 0 ? (
        <div className="ok-card">✓ {t('Aucune récolte aujourd’hui.')}</div>
      ) : (
        harvests.map((h) => (
          <div key={h.id} className="task-row">
            <div className="task-row__body">
              <span className="task-title">🌱 {h.crop ?? t('Culture')}{h.variety ? ' · ' + h.variety : ''}</span>
              <span className="task-meta">
                {h.cycle_code ? h.cycle_code + ' · ' : ''}
                {h.quality ? t(h.quality) : ''}
              </span>
            </div>
            <div className="stock-qty">
              <span className="stock-qty__val">{nf(h.quantity)}</span>
              <span className="stock-qty__unit">{h.unit}</span>
            </div>
          </div>
        ))
      )}
    </div>
  )
}
