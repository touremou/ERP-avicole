/**
 * Couvoir — mirage et éclosion se font EN SALLE d'incubation, œufs (puis
 * poussins) en main. L'écran s'adapte au statut du cycle : « incubation » →
 * mirage, « mirage_fait » → éclosion. Les taux (fertilité, éclosabilité) sont
 * calculés SERVEUR ; l'écran les prévisualise pour donner du sens à la saisie.
 * Contrats : SyncService::incubationMirage / incubationHatch (gate production.M).
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefIncubation } from '../../api/types'

export function HatcheryScreen() {
  const { incubationId } = useParams()
  const navigate = useNavigate()

  const [cycles, setCycles] = useState<RefIncubation[]>([])
  const [selected, setSelected] = useState(incubationId ?? '')
  const [count, setCount] = useState('')
  const [saved, setSaved] = useState<'mirage' | 'hatch' | null>(null)

  useEffect(() => {
    void db.ref_incubations.reverse().sortBy('start_date').then(setCycles)
  }, [])

  const cycle = useMemo(() => cycles.find((c) => c.id === Number(selected)) ?? null, [cycles, selected])
  // Le statut décide de l'étape : pas de choix à faire par l'opérateur.
  const step: 'mirage' | 'hatch' | null = cycle
    ? (cycle.status === 'incubation' ? 'mirage' : cycle.status === 'mirage_fait' ? 'hatch' : null)
    : null

  const value = Number(count) || 0
  const max = step === 'mirage' ? Number(cycle?.eggs_count ?? 0) : Number(cycle?.fertile_eggs ?? 0)
  const over = cycle !== null && value > max
  // Fertilité (mirage) ou éclosabilité (éclosion) — prévisualisation.
  const rate = max > 0 && value > 0 ? Math.round((value / max) * 1000) / 10 : null
  const canSubmit = Boolean(cycle) && step !== null && count !== '' && !over

  useEffect(() => setCount(''), [selected])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit || !cycle || !step) return

    if (step === 'mirage') {
      await enqueue(
        'incubation.mirage',
        { incubation_id: cycle.id, fertile_eggs: value },
        t('Mirage :code — :n œufs fertiles', { code: cycle.code_incubation, n: value }),
      )
    } else {
      await enqueue(
        'incubation.hatch',
        { incubation_id: cycle.id, hatched_chicks: value },
        t('Éclosion :code — :n poussins', { code: cycle.code_incubation, n: value }),
      )
    }

    setSaved(step)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved) {
    return (
      <div className="screen-center">
        <p className="success big">
          {saved === 'mirage' ? t('✓ Mirage enregistré') : t('✓ Éclosion enregistrée')}
        </p>
        <p className="muted">
          {saved === 'mirage'
            ? t('Le taux de fertilité sera calculé par le serveur au push.')
            : t('Le cycle sera clôturé et les poussins mis à dispatcher au push.')}
        </p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🥚 {t('Couvoir')}</h2>

      <label htmlFor="cycle">{t('Cycle d’incubation')}</label>
      <select id="cycle" required value={selected} onChange={(e) => setSelected(e.target.value)}>
        <option value="">{t('Sélectionner…')}</option>
        {cycles.map((c) => (
          <option key={c.id} value={c.id}>
            {c.code_incubation} · {c.eggs_count} {t('œufs')} · {t(c.status)}
          </option>
        ))}
      </select>
      {cycles.length === 0 && (
        <p className="muted">{t('Aucun cycle ouvert en local — synchronisez d’abord.')}</p>
      )}

      {cycle && step === null && (
        <p className="muted">{t('Ce cycle est clos — aucune saisie possible.')}</p>
      )}

      {cycle && step === 'mirage' && (
        <>
          <p className="proof-hint">
            🔎 {t('Mirage — :total œufs mis à couver le :date', {
              total: cycle.eggs_count, date: cycle.start_date,
            })}
          </p>
          <label htmlFor="count">{t('Œufs fertiles (clairs écartés)')}</label>
        </>
      )}

      {cycle && step === 'hatch' && (
        <>
          <p className="proof-hint">
            🐣 {t('Éclosion — :fertile œufs fertiles au mirage', { fertile: cycle.fertile_eggs ?? 0 })}
          </p>
          <label htmlFor="count">{t('Poussins éclos')}</label>
        </>
      )}

      {cycle && step !== null && (
        <>
          <input
            id="count"
            type="number"
            inputMode="numeric"
            min={0}
            max={max}
            required
            value={count}
            onChange={(e) => setCount(e.target.value)}
            placeholder="0"
          />
          {over && (
            <p className="error">
              {step === 'mirage'
                ? t('⚠️ Supérieur aux œufs mis à couver (:max).', { max })
                : t('⚠️ Supérieur aux œufs fertiles (:max).', { max })}
            </p>
          )}
          {rate !== null && !over && (
            <p className="muted">
              {step === 'mirage'
                ? t('Fertilité : :rate %', { rate })
                : t('Éclosabilité : :rate %', { rate })}
            </p>
          )}
        </>
      )}

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {step === 'hatch' ? t('Valider l’éclosion') : t('Valider le mirage')}
      </button>
    </form>
  )
}
