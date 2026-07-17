/**
 * Journal d'abattage du jour (Abattoir) — consultation mobile.
 *
 * Récap (abattus / prévus / bloqués, sujets, poids vif) + liste des ordres du
 * jour. Rafraîchi en ligne, dernier instantané en cache (meta) pour rester
 * consultable hors-ligne.
 */
import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { getMeta, setMeta } from '../../offline/db'
import { t, dateLocale } from '../../i18n'
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

  useEffect(() => {
    void (async () => {
      const cached = await getMeta<SlaughterJournalResponse>(CACHE_KEY)
      if (cached) setData(cached)
      if (navigator.onLine) {
        try {
          const fresh = await api.abattoirToday()
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
  const orders: SlaughterOrderEntry[] = data?.orders ?? []

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Abattage du jour')} 🔪</h2>
        <span className="welcome-sub">
          {new Date().toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
          {offline ? ' · ' + t('hors-ligne (dernier instantané)') : ''}
        </span>
      </div>

      {summary && (
        <div className="kpi-grid">
          <div className="kpi"><div className="kpi-val">{nf(summary.slaughtered)}</div><div className="kpi-lab">{t('Sujets abattus')}</div></div>
          <div className="kpi"><div className="kpi-val">{nf(summary.live_weight_kg)}</div><div className="kpi-lab">{t('Poids vif kg')}</div></div>
          <div className="kpi"><div className="kpi-val">{summary.done}/{summary.total}</div><div className="kpi-lab">{t('OP terminées')}</div></div>
          {summary.blocked > 0 && <div className="kpi kpi--alert"><div className="kpi-val">{summary.blocked}</div><div className="kpi-lab">{t('Bloqués')}</div></div>}
        </div>
      )}

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
