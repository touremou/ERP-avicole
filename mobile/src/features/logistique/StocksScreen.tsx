/**
 * Stocks — écran de CONSULTATION (Logistique). Complément mobile de
 * l'inventaire web : niveaux courants, recherche, alerte de seuil bas, le tout
 * hors-ligne (référentiel synchronisé). Renvoie vers la saisie d'un mouvement
 * pour qui en a le droit.
 */
import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { db } from '../../offline/db'
import { onSyncChange } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefStock } from '../../api/types'

const CATEGORY_ICON: Record<string, string> = {
  oeufs: '🥚',
  conso: '🌾',
  litieres: '🪵',
  materiels: '🧰',
}

function isLow(stock: RefStock): boolean {
  return stock.alert_threshold != null && stock.alert_threshold > 0 && stock.current_quantity <= stock.alert_threshold
}

export function StocksScreen() {
  const { can } = useAuth()
  const [stocks, setStocks] = useState<RefStock[]>([])
  const [query, setQuery] = useState('')

  useEffect(() => {
    const load = async () => setStocks(await db.ref_stocks.orderBy('item_name').toArray())
    void load()
    const off = onSyncChange(() => void load())
    return off
  }, [])

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const list = needle ? stocks.filter((s) => s.item_name.toLowerCase().includes(needle)) : stocks
    // Seuils bas d'abord (les plus urgents en tête).
    return [...list].sort((a, b) => Number(isLow(b)) - Number(isLow(a)))
  }, [stocks, query])

  const lowCount = useMemo(() => stocks.filter(isLow).length, [stocks])

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Stocks')} 📦</h2>
        <span className="welcome-sub">
          {t(':count article(s)', { count: stocks.length })}
          {lowCount > 0 ? ' · ' + t(':count sous le seuil', { count: lowCount }) : ''}
        </span>
      </div>

      {can('logistique', 'M') && (
        <Link to="/logistique/mouvement" className="btn-primary" style={{ display: 'block', textAlign: 'center' }}>
          ＋ {t('Mouvement de stock')}
        </Link>
      )}

      <input
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder={t('Rechercher un article…')}
      />

      {stocks.length === 0 ? (
        <div className="ok-card ok-muted">
          {t('Aucun stock local — la synchronisation les rapatriera au premier passage réseau.')}
        </div>
      ) : (
        filtered.map((stock) => (
          <div key={stock.id} className={`task-row ${isLow(stock) ? 'stock-low' : ''}`}>
            <div className="task-row__body">
              <span className="task-title">{CATEGORY_ICON[stock.category] ?? '📦'} {stock.item_name}</span>
              <span className="task-meta">
                {t(stock.category)}
                {isLow(stock) ? ' · ' + t('Seuil : :n :u', { n: stock.alert_threshold ?? 0, u: stock.unit }) : ''}
              </span>
            </div>
            <div className="stock-qty">
              <span className={`stock-qty__val ${isLow(stock) ? 'stock-qty__val--low' : ''}`}>
                {stock.current_quantity}
              </span>
              <span className="stock-qty__unit">{stock.unit}</span>
            </div>
          </div>
        ))
      )}
    </div>
  )
}
