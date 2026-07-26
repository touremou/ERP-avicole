/**
 * Présence du jour — pointée AU RASSEMBLEMENT du matin, pas au bureau. Toute
 * l'équipe sur un écran, quatre touches par personne, une seule validation :
 * une op = une journée (comme la grille web), pas N saisies.
 *
 * Deux choix contre la corvée :
 *  - tout le monde arrive « présent » : on ne touche que les exceptions ;
 *  - les congés VALIDÉS sont pré-cochés depuis le miroir local, comme le fait
 *    le web — déclarer présent quelqu'un en congé serait une fausse donnée.
 *
 * Contrat : SyncService::attendanceCreate (gate rh.C). Idempotent par
 * (employé, jour) : corriger le soir réécrit, ne duplique pas.
 */
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { enqueue } from '../../offline/sync'
import { safeLoad } from '../../offline/safeLoad'
import { t } from '../../i18n'
import type { RefEmployee, RefEmployeeLeave } from '../../api/types'

/** Miroir de App\Models\EmployeeAttendance::STATUSES. */
const STATUSES = [
  { value: 'present', label: 'Présent', short: '✓', tone: 'ok' },
  { value: 'retard', label: 'Retard', short: '⏱', tone: 'warn' },
  { value: 'absent', label: 'Absent', short: '✕', tone: 'bad' },
  { value: 'conge', label: 'Congé', short: '🌴', tone: 'info' },
] as const

type Status = (typeof STATUSES)[number]['value']

function fullName(employee: RefEmployee): string {
  return [employee.first_name, employee.last_name].filter(Boolean).join(' ').trim()
    || employee.employee_id || `#${employee.id}`
}

/** Un congé validé couvre-t-il la date (bornes incluses, comme le serveur) ? */
function onLeaveOn(leave: RefEmployeeLeave, date: string): boolean {
  return leave.start_date.slice(0, 10) <= date && date <= leave.end_date.slice(0, 10)
}

export function AttendanceScreen() {
  const navigate = useNavigate()
  const today = new Date().toISOString().slice(0, 10)

  const [employees, setEmployees] = useState<RefEmployee[]>([])
  const [leaves, setLeaves] = useState<RefEmployeeLeave[]>([])
  const [statuses, setStatuses] = useState<Record<number, Status>>({})
  const [saved, setSaved] = useState(0)

  useEffect(() => {
    void safeLoad('presence:equipe', async () => {
      const [list, allLeaves] = await Promise.all([
        db.ref_employees.toArray(),
        db.ref_employee_leaves.toArray(),
      ])
      const active = list
        .filter((e) => (e.status ?? 'Actif') === 'Actif')
        .sort((a, b) => fullName(a).localeCompare(fullName(b), 'fr'))
      setEmployees(active)
      setLeaves(allLeaves)

      // Défaut « présent », sauf congé validé couvrant le jour → « congé ».
      const onLeave = new Set(
        allLeaves.filter((l) => onLeaveOn(l, today)).map((l) => l.employee_id),
      )
      setStatuses(
        Object.fromEntries(active.map((e) => [e.id, onLeave.has(e.id) ? 'conge' : 'present'])),
      )
    })
  }, [today])

  const leaveToday = useMemo(
    () => new Set(leaves.filter((l) => onLeaveOn(l, today)).map((l) => l.employee_id)),
    [leaves, today],
  )

  const counts = useMemo(() => {
    const tally: Record<string, number> = { present: 0, retard: 0, absent: 0, conge: 0 }
    for (const employee of employees) {
      const status = statuses[employee.id]
      if (status) tally[status] = (tally[status] ?? 0) + 1
    }
    return tally
  }, [employees, statuses])

  const worked = counts.present + counts.retard
  const canSubmit = employees.length > 0

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit) return

    await enqueue(
      'attendance.create',
      {
        attendance_date: today,
        rows: employees.map((e) => ({ employee_id: e.id, status: statuses[e.id] ?? 'present' })),
      },
      t('Présence du :date — :n présents / :total', {
        date: today, n: worked, total: employees.length,
      }),
    )

    setSaved(employees.length)
    setTimeout(() => navigate('/'), 1100)
  }

  if (saved > 0) {
    return (
      <div className="screen-center">
        <p className="success big">{t('✓ Présence enregistrée (:n personnes)', { n: saved })}</p>
        <p className="muted">{t('Le registre RH sera consolidé au push.')}</p>
      </div>
    )
  }

  return (
    <form className="screen" onSubmit={onSubmit}>
      <h2>🧑‍🌾 {t('Présence du jour')}</h2>
      <p className="muted">
        {t('Tout le monde est présent par défaut — ne touchez que les exceptions.')}
      </p>

      {employees.length === 0 ? (
        <p className="muted">
          {t('Aucun employé en local — synchronisez d’abord (droit RH requis).')}
        </p>
      ) : (
        <>
          <p className="proof-hint">
            👥 {t(':worked présents · :absent absents · :conge en congé', {
              worked, absent: counts.absent, conge: counts.conge,
            })}
          </p>

          {employees.map((employee) => (
            <div key={employee.id} className="round-row">
              <span className="task-title">
                {fullName(employee)}
                {employee.job_title ? <span className="task-meta"> · {employee.job_title}</span> : null}
              </span>
              {leaveToday.has(employee.id) && (
                <span className="task-meta">🌴 {t('congé validé')}</span>
              )}
              <div className="chip-row">
                {STATUSES.map((s) => (
                  <button
                    key={s.value}
                    type="button"
                    className={`chip chip-${s.tone} ${statuses[employee.id] === s.value ? 'chip-on' : ''}`}
                    aria-pressed={statuses[employee.id] === s.value}
                    onClick={() => setStatuses((prev) => ({ ...prev, [employee.id]: s.value }))}
                  >
                    {s.short} {t(s.label)}
                  </button>
                ))}
              </div>
            </div>
          ))}
        </>
      )}

      <button type="submit" className="btn-primary" disabled={!canSubmit}>
        {t('Valider la présence (:n personnes)', { n: employees.length })}
      </button>
    </form>
  )
}
