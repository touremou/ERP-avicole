/**
 * Ma semaine — l'auto-suivi du lundi matin, sur le téléphone.
 *
 * Le technicien voit SES six indicateurs, avec leur cible, avant le point à
 * distance. Il ne voit jamais ceux de ses collègues : ce n'est pas un classement,
 * c'est un miroir. Les chiffres sont calculés SERVEUR (TechnicianWeekService),
 * exactement comme la page web — un écart entre le téléphone et le bureau
 * transformerait le débriefing en discussion sur l'outil.
 *
 * Écran EN LIGNE, volontairement : ces indicateurs agrègent des données que le
 * miroir local ne contient pas (lots d'autrui exclus, normes de souche, cycles).
 * Les recalculer hors réseau donnerait un chiffre différent de celui du bureau.
 */
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import { t, dateLocale } from '../../i18n'
import type { MyWeekResponse } from '../../api/types'

const TONE_CLASS: Record<string, string> = {
  ok: 'kpi-ok',
  warn: 'kpi-warn',
  bad: 'kpi-bad',
  neutral: 'kpi-neutral',
}

function formatValue(value: number | null, unit: string): string {
  if (value === null) return '—'
  const digits = unit === '%' ? 1 : 2
  return `${value.toLocaleString(dateLocale(), { minimumFractionDigits: digits, maximumFractionDigits: digits })}${unit}`
}

export function MaSemaineScreen() {
  const [data, setData] = useState<MyWeekResponse | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  /** Décalage en semaines : 0 = semaine en cours, -1 = la précédente. */
  const [offset, setOffset] = useState(0)

  const load = useCallback(async (weeksBack: number) => {
    setLoading(true)
    setError(null)
    try {
      const target = new Date()
      target.setDate(target.getDate() + weeksBack * 7)
      // Le serveur accepte une date libre et la ramène au lundi de sa semaine :
      // pas de calcul de numéro ISO côté client (source d'erreurs en fin d'année).
      const response = await api.myWeek(target.toISOString().slice(0, 10))
      setData(response)
    } catch {
      setError(t('Indicateurs indisponibles hors réseau — ils sont calculés au bureau.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void load(offset) }, [load, offset])

  if (loading && !data) {
    return <div className="screen-center"><p className="muted">{t('Chargement…')}</p></div>
  }

  if (error) {
    return (
      <div className="screen">
        <h2>📊 {t('Ma semaine')}</h2>
        <p className="muted">{error}</p>
        <button type="button" className="btn-secondary" onClick={() => void load(offset)}>
          {t('Réessayer')}
        </button>
        <Link to="/mon-espace" className="muted center-link">{t('← Mon espace')}</Link>
      </div>
    )
  }

  if (!data?.has_sheet) {
    return (
      <div className="screen">
        <h2>📊 {t('Ma semaine')}</h2>
        <p className="muted">
          {t('Votre compte n’est pas rattaché à une fiche employé : aucun suivi personnel à afficher.')}
        </p>
        <Link to="/mon-espace" className="muted center-link">{t('← Mon espace')}</Link>
      </div>
    )
  }

  const tasks = data.tasks
  const week = data.week

  return (
    <div className="screen">
      <h2>📊 {t('Ma semaine')}</h2>
      {week && (
        <p className="muted">
          {t('Semaine :n', { n: week.iso })} · {new Date(week.from).toLocaleDateString(dateLocale())} → {new Date(week.to).toLocaleDateString(dateLocale())}
        </p>
      )}

      <div className="chip-row">
        <button type="button" className="chip" onClick={() => setOffset((o) => o - 1)}>
          ← {t('Semaine précédente')}
        </button>
        {offset !== 0 && (
          <button type="button" className="chip chip-on" onClick={() => setOffset(0)}>
            {t('Revenir à cette semaine')}
          </button>
        )}
      </div>

      {tasks && tasks.total === 0 && (
        <p className="proof-hint">
          ℹ️ {t('Aucune tâche planifiée cette semaine — rien à mesurer.')}
        </p>
      )}

      {(data.indicators ?? []).map((indicator) => (
        <div key={indicator.key} className={`kpi-row ${TONE_CLASS[indicator.tone] ?? 'kpi-neutral'}`}>
          <span className="kpi-label">{t(indicator.label)}</span>
          <span className="kpi-value">
            {indicator.value === null
              ? <em className="kpi-na">{t('non mesurable')}</em>
              : formatValue(indicator.value, indicator.unit)}
          </span>
          <span className="kpi-target">{t('cible')} {indicator.target}</span>
          <span className="kpi-detail">{indicator.detail}</span>
        </div>
      ))}

      {(data.batches ?? []).length > 0 && (
        <section>
          <h3>{t('Mes lots')}</h3>
          {(data.batches ?? []).map((batch) => (
            <div key={batch.id} className="record-row">
              <span>
                {batch.code}
                <span className="task-meta"> · J{batch.age_days} · {batch.current.toLocaleString(dateLocale())} {t('sujets')}</span>
              </span>
              <span className="task-meta">
                {t('mort.')} {batch.mortality_rate.toLocaleString(dateLocale())} %
                {batch.fcr !== null ? ` · FCR ${batch.fcr}` : ''}
              </span>
            </div>
          ))}
        </section>
      )}

      {(data.cycles ?? []).length > 0 && (
        <section>
          <h3>{t('Mes cultures')}</h3>
          {(data.cycles ?? []).map((cycle) => (
            <div key={cycle.id} className="record-row">
              <span>
                {cycle.crop_name}
                <span className="task-meta">
                  {' · '}{cycle.plot ?? cycle.code}
                  {cycle.days_after_planting !== null ? ` · J+${cycle.days_after_planting}` : ''}
                </span>
              </span>
              <span className={cycle.steps_late > 0 ? 'error' : 'task-meta'}>
                {cycle.steps_done}/{cycle.steps_total}
                {cycle.steps_late > 0 ? ` · ${cycle.steps_late} ${t('en retard')}` : ''}
              </span>
            </div>
          ))}
        </section>
      )}

      {(data.incidents ?? 0) > 0 && (
        <p className="muted">
          ⚠️ {t(':n incident(s) sanitaire(s) déclaré(s) cette semaine', { n: data.incidents ?? 0 })}
        </p>
      )}

      <p className="muted">
        {t('La ponctualité se mesure sur la date où vous avez fait la tâche, pas sur celle de la synchronisation.')}
      </p>

      <Link to="/mon-espace" className="muted center-link">{t('← Mon espace')}</Link>
    </div>
  )
}
