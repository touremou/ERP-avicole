/**
 * Journal des récoltes du jour (Cultures) — consultation mobile.
 *
 * Récap (nombre, poids net cumulé) + liste des récoltes du jour. Rafraîchi en
 * ligne, dernier instantané en cache (meta) pour rester consultable hors-ligne.
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
import type { HarvestJournalResponse, HarvestEntry } from '../../api/types'

const CACHE_KEY = 'harvest_journal_today'

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function HarvestJournalScreen() {
  const [data, setData] = useState<HarvestJournalResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [offline, setOffline] = useState(false)
  const [crop, setCrop] = useState('all')
  const [period, setPeriod] = useState('today')

  useEffect(() => {
    void (async () => {
      setLoading(true)
      const cacheKey = `${CACHE_KEY}_${period}`
      const cached = await getMeta<HarvestJournalResponse>(cacheKey)
      setData(cached ?? null)
      if (navigator.onLine) {
        try {
          const fresh = await api.culturesToday(period)
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
  const allHarvests: HarvestEntry[] = data?.harvests ?? []
  const harvests = useMemo(
    () => (crop === 'all' ? allHarvests : allHarvests.filter((h) => (h.crop ?? '') === crop)),
    [allHarvests, crop],
  )

  // Répartition du poids récolté par culture (graphique).
  const byCrop = useMemo(() => {
    const map = new Map<string, number>()
    for (const h of allHarvests) {
      const key = h.crop ?? t('Culture')
      map.set(key, (map.get(key) ?? 0) + h.weight_kg)
    }
    return [...map.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value)
  }, [allHarvests])

  const chips = useMemo(() => {
    const crops = [...new Set(allHarvests.map((h) => h.crop ?? '').filter(Boolean))]
    return [
      { key: 'all', label: t('Tous'), count: allHarvests.length },
      ...crops.map((c) => ({ key: c, label: c, count: allHarvests.filter((h) => h.crop === c).length })),
    ]
  }, [allHarvests])

  function handleExport() {
    const csv = toCsv(
      [t('Culture'), t('Variété'), t('Cycle'), t('Quantité'), t('Unité'), t('Qualité')],
      harvests.map((h) => [h.crop ?? '', h.variety ?? '', h.cycle_code ?? '', h.quantity, h.unit, h.quality ?? '']),
    )
    void exportOrShare(`recoltes_${period}_${dateStamp()}.csv`, csv, t('Récoltes du jour'))
  }

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Récoltes du jour')} 🌱</h2>
        <span className="welcome-sub">
          {new Date().toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
          {offline ? ' · ' + t('hors-ligne (dernier instantané)') : ''}
        </span>
      </div>

      <PeriodSelector period={period} onChange={setPeriod} />
      <ExportButton onExport={handleExport} disabled={harvests.length === 0} />

      {summary && (
        <div className="kpi-grid">
          <div className="kpi"><div className="kpi-val">{nf(summary.total_weight_kg)}</div><div className="kpi-lab">{t('kg récoltés')}</div></div>
          <div className="kpi"><div className="kpi-val">{summary.count}</div><div className="kpi-lab">{t('Récoltes')}</div></div>
        </div>
      )}

      {period === '7days' && data?.series && <TimeSeriesChart points={data.series} unit="kg" title={t('Récoltes · 7 jours')} />}
      {byCrop.length > 0 && <BarBreakdown items={byCrop} unit="kg" />}
      {chips.length > 1 && <FilterChips options={chips} active={crop} onChange={setCrop} />}

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
