/**
 * TimeSeriesChart — courbe temporelle légère (SVG, sans dépendance) : une
 * valeur par jour sur la période. Aire + ligne + points, étiquette de la
 * dernière valeur, axe X = jour du mois. Rien affiché sous 2 points.
 */
import { t } from '../i18n'

export interface SeriesPoint {
  date: string
  value: number
}

function nf(value: number): string {
  return new Intl.NumberFormat('fr-FR').format(Math.round(value))
}

export function TimeSeriesChart({ points, unit, title }: { points: SeriesPoint[]; unit?: string; title?: string }) {
  if (!points || points.length < 2) return null

  const w = 320
  const h = 96
  const padX = 10
  const padTop = 12
  const padBottom = 20
  const max = Math.max(1, ...points.map((p) => p.value))
  const stepX = (w - padX * 2) / (points.length - 1)
  const yOf = (value: number) => h - padBottom - (value / max) * (h - padTop - padBottom)
  const coords = points.map((p, i) => [padX + i * stepX, yOf(p.value)] as const)
  const line = coords.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const area = `${padX},${h - padBottom} ${line} ${(padX + (points.length - 1) * stepX).toFixed(1)},${h - padBottom}`
  const last = points[points.length - 1]

  return (
    <div className="card-plain">
      <span className="task-meta">{title ?? t('7 derniers jours')}{unit ? ' · ' + unit : ''}</span>
      <svg viewBox={`0 0 ${w} ${h}`} className="tschart" role="img" aria-label={title ?? t('Courbe 7 jours')}>
        <polygon points={area} fill="#2563eb" opacity="0.10" />
        <polyline points={line} fill="none" stroke="#2563eb" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
        {coords.map(([x, y], i) => (
          <g key={i}>
            <circle cx={x} cy={y} r="2.4" fill="#2563eb" />
            <text x={x} y={h - 6} textAnchor="middle" className="tschart-x">{points[i].date.slice(8, 10)}</text>
          </g>
        ))}
        <text x={coords[coords.length - 1][0]} y={Math.max(10, coords[coords.length - 1][1] - 6)} textAnchor="end" className="tschart-val">{nf(last.value)}</text>
      </svg>
    </div>
  )
}
