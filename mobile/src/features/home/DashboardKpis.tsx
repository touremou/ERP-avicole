/**
 * DashboardKpis — indicateurs consolidés de l'accueil, ADAPTÉS AU RÔLE.
 *
 * Chaque carte n'apparaît que si l'utilisateur a le droit de LIRE (L) le
 * module concerné (gate hors-ligne depuis les permissions en cache). Tout est
 * calculé depuis le miroir Dexie — aucun appel réseau. Objectif : faire de
 * l'accueil un vrai tableau de bord terrain, pas un simple aiguillage.
 */
import { useEffect, useState } from 'react'
import { db } from '../../offline/db'
import { onSyncChange } from '../../offline/sync'
import { t } from '../../i18n'
import { useFieldTasks } from './useFieldTasks'

type Kpi = { key: string; value: string; label: string; alert?: boolean }

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function DashboardKpis({ todo }: { todo: number }) {
  const { can, batches, slaughterOrders, millProductions, cropCycles, savedToday } = useFieldTasks()
  const [stocks, setStocks] = useState({ count: 0, low: 0 })
  const [commerce, setCommerce] = useState({ clients: 0, receivables: 0 })

  useEffect(() => {
    const load = async () => {
      if (can('logistique', 'L')) {
        const all = await db.ref_stocks.toArray()
        const low = all.filter((s) => s.alert_threshold != null && s.alert_threshold > 0 && s.current_quantity <= s.alert_threshold).length
        setStocks({ count: all.length, low })
      }
      if (can('commerce', 'L')) {
        const clients = await db.ref_clients.toArray()
        const receivables = clients.reduce((sum, client) => sum + Math.max(0, client.balance ?? 0), 0)
        setCommerce({ clients: clients.length, receivables })
      }
    }
    void load()
    return onSyncChange(() => void load())
  }, [can])

  const cards: Kpi[] = []

  // Universel : ce qu'il reste à faire + saisies du jour.
  cards.push({ key: 'todo', value: nf(todo), label: t('À faire') })
  cards.push({ key: 'saved', value: nf(savedToday), label: t('Saisies auj.') })

  if (can('elevage', 'L')) {
    const effectif = batches.reduce((sum, b) => sum + (b.current_quantity ?? 0), 0)
    cards.push({ key: 'effectif', value: nf(effectif), label: t('Effectif actif') })
    cards.push({ key: 'lots', value: nf(batches.length), label: t('Lots actifs') })
  }
  if (can('logistique', 'L')) {
    cards.push({ key: 'stocks', value: nf(stocks.count), label: t('Articles stock') })
    if (stocks.low > 0) cards.push({ key: 'low', value: nf(stocks.low), label: t('Seuil bas'), alert: true })
  }
  if (can('commerce', 'L')) {
    cards.push({ key: 'clients', value: nf(commerce.clients), label: t('Clients') })
    if (commerce.receivables > 0) cards.push({ key: 'creances', value: nf(commerce.receivables), label: t('Créances'), alert: true })
  }
  if (can('abattoir', 'L') && slaughterOrders.length > 0) {
    cards.push({ key: 'abattage', value: nf(slaughterOrders.length), label: t('Ordres à exécuter') })
  }
  if (can('provenderie', 'L') && millProductions.length > 0) {
    cards.push({ key: 'op', value: nf(millProductions.length), label: t('OP en cours') })
  }
  if (can('cultures', 'L') && cropCycles.length > 0) {
    cards.push({ key: 'cycles', value: nf(cropCycles.length), label: t('Cycles en cours') })
  }

  return (
    <div className="kpi-grid">
      {cards.map((card) => (
        <div key={card.key} className={`kpi ${card.alert ? 'kpi--alert' : ''}`}>
          <div className="kpi-val">{card.value}</div>
          <div className="kpi-lab">{card.label}</div>
        </div>
      ))}
    </div>
  )
}
