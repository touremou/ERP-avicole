/**
 * Journal de trésorerie du jour (Trésorerie) — consultation mobile.
 *
 * Récap (encaissé / décaissé / net) + soldes courants par compte + liste des
 * mouvements du jour. Rafraîchi en ligne ; dernier instantané en cache (meta)
 * pour rester consultable hors-ligne.
 */
import { useEffect, useMemo, useState } from 'react'
import { api } from '../../api/client'
import { getMeta, setMeta } from '../../offline/db'
import { t, dateLocale } from '../../i18n'
import { FilterChips } from '../../ui/FilterChips'
import { BarBreakdown } from '../../ui/BarBreakdown'
import { PeriodSelector } from '../../ui/PeriodSelector'
import { ExportButton } from '../../ui/ExportButton'
import { toCsv, exportOrShare, dateStamp } from '../../ui/exportShare'
import type { TreasuryJournalResponse, TreasuryMovement } from '../../api/types'

const CACHE_KEY = 'treasury_journal_today'

const TYPE_ICON: Record<string, string> = {
  caisse: '💵',
  mobile_money: '📱',
  banque: '🏦',
  autre: '💼',
}

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function TreasuryJournalScreen() {
  const [data, setData] = useState<TreasuryJournalResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [offline, setOffline] = useState(false)
  const [dir, setDir] = useState('all')
  const [period, setPeriod] = useState('today')

  useEffect(() => {
    void (async () => {
      setLoading(true)
      const cacheKey = `${CACHE_KEY}_${period}`
      const cached = await getMeta<TreasuryJournalResponse>(cacheKey)
      setData(cached ?? null)
      if (navigator.onLine) {
        try {
          const fresh = await api.treasuryToday(period)
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
  const allMovements: TreasuryMovement[] = data?.movements ?? []
  const movements = useMemo(
    () => (dir === 'all' ? allMovements : allMovements.filter((m) => m.direction === dir)),
    [allMovements, dir],
  )
  const breakdown = summary
    ? [
        { label: t('Encaissé'), value: summary.in, color: '#16a34a' },
        { label: t('Décaissé'), value: summary.out, color: '#dc2626' },
      ]
    : []
  const chips = [
    { key: 'all', label: t('Tous'), count: allMovements.length },
    { key: 'in', label: t('Entrées'), count: allMovements.filter((m) => m.direction === 'in').length },
    { key: 'out', label: t('Sorties'), count: allMovements.filter((m) => m.direction === 'out').length },
  ]

  function handleExport() {
    const csv = toCsv(
      [t('Compte'), t('Sens'), t('Montant'), t('Catégorie'), t('Description')],
      movements.map((m) => [m.account ?? '', m.direction === 'in' ? t('Entrées') : t('Sorties'), m.amount, m.category ?? '', m.description ?? '']),
    )
    void exportOrShare(`tresorerie_${period}_${dateStamp()}.csv`, csv, t('Trésorerie du jour'))
  }

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Trésorerie du jour')} 💰</h2>
        <span className="welcome-sub">
          {new Date().toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
          {offline ? ' · ' + t('hors-ligne (dernier instantané)') : ''}
        </span>
      </div>

      <PeriodSelector period={period} onChange={setPeriod} />
      <ExportButton onExport={handleExport} disabled={movements.length === 0} />

      {summary && (
        <div className="kpi-grid">
          <div className="kpi"><div className="kpi-val">{nf(summary.in)}</div><div className="kpi-lab">{t('Encaissé')}</div></div>
          <div className="kpi"><div className="kpi-val">{nf(summary.out)}</div><div className="kpi-lab">{t('Décaissé')}</div></div>
          <div className={`kpi ${summary.net < 0 ? 'kpi--alert' : ''}`}><div className="kpi-val">{nf(summary.net)}</div><div className="kpi-lab">{t('Net du jour')}</div></div>
          {data && <div className="kpi"><div className="kpi-val">{nf(data.total_balance)}</div><div className="kpi-lab">{t('Solde total')}</div></div>}
        </div>
      )}

      {data && data.accounts.length > 0 && (
        <section>
          <div className="section-head"><h3>{t('Soldes par compte')}</h3></div>
          {data.accounts.map((account) => (
            <div key={account.id} className={`task-row ${account.is_active ? '' : 'task-muted'}`}>
              <div className="task-row__body">
                <span className="task-title">{TYPE_ICON[account.type] ?? '💼'} {account.name}</span>
              </div>
              <div className="stock-qty"><span className="stock-qty__val">{nf(account.balance)}</span></div>
            </div>
          ))}
        </section>
      )}

      <div className="section-head"><h3>{t('Mouvements du jour')}</h3><span className="section-count">{movements.length}</span></div>
      {allMovements.length > 0 && <BarBreakdown items={breakdown} />}
      {allMovements.length > 0 && <FilterChips options={chips} active={dir} onChange={setDir} />}
      {loading && !data ? (
        <div className="ok-card ok-muted">{t('Chargement…')}</div>
      ) : movements.length === 0 ? (
        <div className="ok-card">✓ {t('Aucun mouvement aujourd’hui.')}</div>
      ) : (
        movements.map((mv) => (
          <div key={mv.id} className="task-row">
            <div className="task-row__body">
              <span className="task-title">{mv.account ?? '—'}</span>
              <span className="task-meta">
                {mv.created_at ? new Date(mv.created_at).toLocaleTimeString(dateLocale(), { hour: '2-digit', minute: '2-digit' }) + ' · ' : ''}
                {mv.description || t(mv.category ?? 'divers')}
              </span>
            </div>
            <div className="stock-qty">
              <span className={`stock-qty__val ${mv.direction === 'in' ? 'amount-in' : 'amount-out'}`}>
                {mv.direction === 'in' ? '+' : '−'}{nf(mv.amount)}
              </span>
            </div>
          </div>
        ))
      )}
    </div>
  )
}
