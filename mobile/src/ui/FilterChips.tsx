/**
 * FilterChips — rangée de puces de filtre (sélection unique), réutilisée par
 * les journaux mobiles. Chaque puce peut afficher un compteur.
 */
export interface ChipOption {
  key: string
  label: string
  count?: number
}

export function FilterChips({
  options,
  active,
  onChange,
}: {
  options: ChipOption[]
  active: string
  onChange: (key: string) => void
}) {
  return (
    <div className="chip-row">
      {options.map((option) => (
        <button
          type="button"
          key={option.key}
          className={`chip ${active === option.key ? 'chip-on' : ''}`}
          onClick={() => onChange(option.key)}
        >
          {option.label}
          {option.count != null ? ` (${option.count})` : ''}
        </button>
      ))}
    </div>
  )
}
