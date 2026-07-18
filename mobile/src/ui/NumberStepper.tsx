/**
 * Stepper numérique — règle UX n°4 : le clavier est l'exception. Gros
 * boutons +/- utilisables avec des gants, saisie directe possible au tap
 * sur la valeur (clavier numérique).
 */
interface Props {
  label: string
  value: number
  onChange: (value: number) => void
  min?: number
  max?: number
  step?: number
}

export function NumberStepper({ label, value, onChange, min = 0, max = 99999, step = 1 }: Props) {
  const clamp = (v: number) => Math.min(max, Math.max(min, v))

  return (
    <div className="stepper">
      <span className="stepper-label">{label}</span>
      <div className="stepper-controls">
        <button
          type="button"
          className="stepper-btn"
          aria-label={`Diminuer ${label}`}
          onClick={() => onChange(clamp(value - step))}
        >
          −
        </button>
        <input
          type="number"
          inputMode="numeric"
          value={value}
          min={min}
          max={max}
          aria-label={label}
          // Sélectionne le « 0 » au focus : taper le remplace directement, au
          // lieu de devoir l'effacer d'abord (0 qui « persiste » dans le champ).
          onFocus={(e) => e.target.select()}
          onChange={(e) => onChange(clamp(Number(e.target.value) || 0))}
        />
        <button
          type="button"
          className="stepper-btn"
          aria-label={`Augmenter ${label}`}
          onClick={() => onChange(clamp(value + step))}
        >
          +
        </button>
      </div>
    </div>
  )
}
