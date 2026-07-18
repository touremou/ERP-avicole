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
import { FilterChips } from '../../ui/FilterChips'
import { BarBreakdown } from '../../ui/BarBreakdown'
import { ExportButton } from '../../ui/ExportButton'
import { toCsv, exportOrShare, dateStamp } from '../../ui/exportShare'
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
  const [cat, setCat] = useState('all') // 'all' | 'low' | <catégorie>

  useEffect(() => {
    // NB : trier en JS, pas via Dexie orderBy('item_name') — item_name n'est PAS
    // un index du store ref_stocks (id, category), donc orderBy jetait et la
    // liste restait vide même avec des stocks bien synchronisés (« 0 article »).
    const load = async () => {
      const all = await db.ref_stocks.toArray()
      setStocks(all.sort((a, b) => a.item_name.localeCompare(b.item_name)))
    }
    void load()
    const off = onSyncChange(() => void load())
    return off
  }, [])

  const lowCount = useMemo(() => stocks.filter(isLow).length, [stocks])

  // Répartition du nombre d'articles par catégorie (graphique).
  const byCategory = useMemo(() => {
    const map = new Map<string, number>()
    for (const s of stocks) map.set(s.category, (map.get(s.category) ?? 0) + 1)
    return [...map.entries()].map(([label, value]) => ({ label: t(label), value })).sort((a, b) => b.value - a.value)
  }, [stocks])

  const chips = useMemo(() => {
    const cats = [...new Set(stocks.map((s) => s.category))]
    return [
      { key: 'all', label: t('Tous'), count: stocks.length },
      { key: 'low', label: t('Seuil bas'), count: lowCount },
      ...cats.map((c) => ({ key: c, label: t(c), count: stocks.filter((s) => s.category === c).length })),
    ]
  }, [stocks, lowCount])

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    let list = needle ? stocks.filter((s) => s.item_name.toLowerCase().includes(needle)) : stocks
    if (cat === 'low') list = list.filter(isLow)
    else if (cat !== 'all') list = list.filter((s) => s.category === cat)
    // Seuils bas d'abord (les plus urgents en tête).
    return [...list].sort((a, b) => Number(isLow(b)) - Number(isLow(a)))
  }, [stocks, query, cat])

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

      {stocks.length > 0 && <BarBreakdown items={byCategory} />}
      {stocks.length > 0 && <FilterChips options={chips} active={cat} onChange={setCat} />}
      {stocks.length > 0 && (
        <ExportButton
          onExport={() => {
            const csv = toCsv(
              [t('Article'), t('Catégorie'), t('Quantité'), t('Unité'), t('Seuil')],
              filtered.map((s) => [s.item_name, t(s.category), s.current_quantity, s.unit, s.alert_threshold ?? '']),
            )
            void exportOrShare(`stocks_${dateStamp()}.csv`, csv, t('Stocks'))
          }}
          disabled={filtered.length === 0}
        />
      )}

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
