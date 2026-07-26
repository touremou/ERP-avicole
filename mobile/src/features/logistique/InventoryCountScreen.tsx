/**
 * Inventaire physique — le magasinier compte DEVANT le rayon. Grille : un
 * article par ligne, gros champ pour la quantité comptée, écart calculé en
 * direct par rapport au stock théorique. Une seule validation → une op de sync
 * PAR article compté (idempotence et bac « À corriger » ligne par ligne).
 *
 * Un comptage identique au stock est un SUCCÈS métier (il confirme le stock) :
 * le serveur l'absorbe sans créer d'ajustement.
 * Contrat : SyncService::inventoryCountCreate (gate logistique.C).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefStock } from '../../api/types'

export function InventoryCountScreen() {
  const navigate = useNavigate()

  const [stocks, setStocks] = useState<RefStock[]>([])
  const [category, setCategory] = useState<string>('')
  const [search, setSearch] = useState('')
  const [counts, setCounts] = useState<Record<number, string>>({})
  const [notes, setNotes] = useState('')
  const [saved, setSaved] = useState(0)

  useEffect(() => {
    void db.ref_stocks.toArray().then((rows) =>
      setStocks(rows.sort((a, b) => a.item_name.localeCompare(b.item_name))),
    )
  }, [])

  const categories = useMemo(
    () => Array.from(new Set(stocks.map((s) => s.category))).sort(),
    [stocks],
  )

  // Le magasinier compte rayon par rayon : filtrer par catégorie évite de
  // faire défiler tout le magasin (et de saisir dans la mauvaise ligne).
  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase()
    return stocks.filter((s) =>
      (category === '' || s.category === category) &&
      (needle === '' || s.item_name.toLowerCase().includes(needle)),
    )
  }, [stocks, category, search])

  const counted = stocks.filter((s) => (counts[s.id] ?? '') !== '' && !Number.isNaN(Number(counts[s.id])))
  const gapOf = (stock: RefStock) => {
    const value = counts[stock.id]
    if (value === undefined || value === '') return null
    const n = Number(value)
    if (Number.isNaN(n)) return null
    return Math.round((n - Number(stock.current_quantity)) * 1000) / 1000
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (counted.length === 0) return

    for (const stock of counted) {
      await enqueue(
        'inventory_count.create',
        {
          stock_id: stock.id,
          counted_quantity: Number(counts[stock.id]),
          count_date: new Date().toISOString().slice(0, 10),
          notes: notes.trim() || null,
        },
        t('Inventaire :item : :qty :unit', {
          item: stock.item_name, qty: counts[stock.id], unit: stock.unit,
        }),
      )
    }

    setSaved(counted.length)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved > 0) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Inventaire enregistré (:n articles)', { n: saved })}</p>
        <p className="muted">{t('Les écarts seront chiffrés et tracés par le serveur au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>📋 {t('Inventaire physique')}</h2>
      <p className="muted">{t('Comptez les articles présents — laissez vide ce que vous ne comptez pas.')}</p>

      <label htmlFor="cat">{t('Rayon / catégorie')}</label>
      <select id="cat" value={category} onChange={(e) => setCategory(e.target.value)}>
        <option value="">{t('Toutes')}</option>
        {categories.map((c) => (
          <option key={c} value={c}>{t(c)}</option>
        ))}
      </select>

      <label htmlFor="search">{t('Rechercher un article…')}</label>
      <input id="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Rechercher un article…')} />

      {visible.map((stock) => {
        const gap = gapOf(stock)
        return (
          <div key={stock.id} className="round-row">
            <div className="cut-line">
              <span className="cut-label">
                {stock.item_name}
                <span className="muted"> · {t('théorique :qty :unit', { qty: stock.current_quantity, unit: stock.unit })}</span>
              </span>
              <input
                type="number"
                inputMode="decimal"
                min={0}
                step="0.001"
                value={counts[stock.id] ?? ''}
                onChange={(e) => setCounts((prev) => ({ ...prev, [stock.id]: e.target.value }))}
                placeholder="—"
                aria-label={stock.item_name}
              />
            </div>
            {gap !== null && (
              <p className={gap === 0 ? 'muted' : 'error'}>
                {gap === 0
                  ? t('✓ Conforme au stock théorique')
                  : t('Écart : :gap :unit', { gap: gap > 0 ? `+${gap}` : gap, unit: stock.unit })}
              </p>
            )}
          </div>
        )
      })}

      {stocks.length === 0 && (
        <p className="muted">{t('Aucun stock local — la synchronisation les rapatriera au premier passage réseau.')}</p>
      )}

      <label htmlFor="notes">{t('Note de comptage — optionnel')}</label>
      <input id="notes" maxLength={500} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={t('ex. inventaire mensuel')} />

      <button type="submit" className="btn-primary" disabled={counted.length === 0}>
        {t('Valider l’inventaire (:n articles)', { n: counted.length })}
      </button>
    </form>
  )
}
