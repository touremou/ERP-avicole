/**
 * BarBreakdown — mini-graphique en barres horizontales (répartition), réutilisé
 * par les journaux mobiles. Léger, sans dépendance : normalisé sur la valeur
 * max, valeur affichée à droite. Les entrées à zéro sont masquées.
 */
export interface BarItem {
  label: string
  value: number
  color?: string
}

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function BarBreakdown({ items, unit }: { items: BarItem[]; unit?: string }) {
  const shown = items.filter((item) => item.value > 0)
  if (shown.length === 0) return null
  const max = Math.max(...shown.map((item) => item.value))

  return (
    <div className="bars">
      {shown.map((item, i) => (
        <div key={i} className="bar-row">
          <span className="bar-label">{item.label}</span>
          <div className="bar-track">
            <div
              className="bar-fill"
              style={{ width: `${Math.max(4, (item.value / max) * 100)}%`, background: item.color ?? 'var(--primary, #2563eb)' }}
            />
          </div>
          <span className="bar-value">{nf(item.value)}{unit ? ' ' + unit : ''}</span>
        </div>
      ))}
    </div>
  )
}
