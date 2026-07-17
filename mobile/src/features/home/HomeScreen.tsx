/**
 * Accueil = TABLEAU DE BORD (lecture). Les lanceurs de saisie ont migré vers
 * l'écran « Nouvelle saisie » (bouton +) : ici on ne montre QUE des synthèses
 * — indicateurs du jour, ce qu'il reste à traiter (renvoi vers le +), et un
 * aperçu des dernières alertes. Données agrégées hors-ligne (Dexie).
 */
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { db } from '../../offline/db'
import { onSyncChange, enqueue } from '../../offline/sync'
import { t, dateLocale } from '../../i18n'
import { useFieldTasks } from './useFieldTasks'
import { DashboardKpis } from './DashboardKpis'
import type { ApiNotification, RefTask } from '../../api/types'

const SEVERITY_CLASS: Record<string, string> = {
  critical: 'notif-critical',
  warning: 'notif-warning',
  normal: 'notif-normal',
}

const CATEGORY_ICON: Record<string, string> = {
  alimentation: '🌾',
  collecte: '🥚',
  nettoyage: '🧽',
  sante: '🩺',
  controle: '📋',
  maintenance: '🔧',
}

export function HomeScreen() {
  const { me, can } = useAuth()
  const { batches, checksTodo, eggsTodo, slaughterOrders, millProductions, cropCycles } = useFieldTasks()
  const [alerts, setAlerts] = useState<ApiNotification[]>([])
  const [tasks, setTasks] = useState<RefTask[]>([])

  useEffect(() => {
    const load = async () => {
      setAlerts(await db.notifications.orderBy('created_at').reverse().limit(3).toArray())
      // Tâches assignées « du jour » : à faire aujourd'hui ou en retard.
      const today = new Date().toISOString().slice(0, 10)
      const all = await db.tasks.toArray()
      setTasks(all.filter((t) => t.scheduled_date <= today).sort((a, b) => a.scheduled_date.localeCompare(b.scheduled_date)))
    }
    void load()
    const onUpdate = () => void load()
    window.addEventListener('notifications:updated', onUpdate)
    window.addEventListener('tasks:updated', onUpdate)
    const off = onSyncChange(() => void load())
    return () => {
      window.removeEventListener('notifications:updated', onUpdate)
      window.removeEventListener('tasks:updated', onUpdate)
      off()
    }
  }, [])

  async function completeTask(task: RefTask) {
    // Optimiste : on retire la tâche localement et on pousse l'opération.
    await enqueue('task.complete', { task_id: task.id }, t('Tâche : :title', { title: task.title }))
    await db.tasks.delete(task.id)
    window.dispatchEvent(new CustomEvent('tasks:updated'))
  }

  const canElevage = can('elevage', 'C')
  const canProduction = can('production', 'C')
  const canCultures = can('cultures', 'C')
  const canAbattoir = can('abattoir', 'M')
  const canProvenderie = can('provenderie', 'M')

  // Détail de ce qui reste à traiter, par catégorie permise.
  const breakdown: { label: string; count: number }[] = []
  if (canElevage) breakdown.push({ label: t('Pointages'), count: checksTodo.length })
  if (canProduction) breakdown.push({ label: t("Collectes"), count: eggsTodo.length })
  if (canAbattoir) breakdown.push({ label: t('Abattages'), count: slaughterOrders.length })
  if (canProvenderie) breakdown.push({ label: t('OP'), count: millProductions.length })
  if (canCultures) breakdown.push({ label: t('Cultures'), count: cropCycles.length })
  const totalTodo = breakdown.reduce((sum, b) => sum + b.count, 0)

  const today = new Date()
  const todayStr = today.toISOString().slice(0, 10)
  const dateLabel = today.toLocaleDateString(dateLocale(), { weekday: 'long', day: 'numeric', month: 'long' })

  return (
    <div className="screen">
      <div className="welcome">
        <span className="welcome-eyebrow">{dateLabel}</span>
        <h2>{t('Bonjour')} {me?.user.name?.split(' ')[0]} 👋</h2>
        <span className="welcome-sub">
          {totalTodo > 0
            ? t(':count tâche(s) à traiter aujourd’hui', { count: totalTodo })
            : t('Rien d’urgent — bonne journée sur le terrain.')}
        </span>
      </div>

      {/* KPIs consolidés, adaptés au rôle (gate hors-ligne par module). */}
      <DashboardKpis todo={totalTodo} />

      {/* Mes tâches assignées du jour (planifiées par le responsable).
          On ne l'affiche QUE si le compte est rattaché à une fiche employé —
          sinon on explique pourquoi aucune tâche ne peut apparaître. */}
      {me?.scope.employee_id != null ? (
        <section>
          <div className="section-head"><h3>{t('Mes tâches du jour')}</h3><Link to="/taches" className="section-link">{t('Voir tout')}</Link></div>
          {tasks.length > 0 ? (
            tasks.map((task) => {
              const overdue = task.scheduled_date < todayStr
              return (
                <div key={task.id} className="task-row">
                  <div className="task-row__body">
                    <span className="task-title">{CATEGORY_ICON[task.category] ?? '📌'} {task.title}</span>
                    <span className="task-meta">
                      {task.scheduled_time ? task.scheduled_time.slice(0, 5) + ' · ' : ''}
                      {overdue ? <span className="task-overdue">{t('En retard')}</span> : t(task.category)}
                    </span>
                  </div>
                  <button type="button" className="task-done" onClick={() => void completeTask(task)}>
                    ✓ {t('Fait')}
                  </button>
                </div>
              )
            })
          ) : (
            <div className="ok-card">✓ {t('Aucune tâche assignée aujourd’hui.')}</div>
          )}
        </section>
      ) : (
        <div className="ok-card ok-muted">
          {t('ℹ️ Votre compte n’est pas rattaché à une fiche employé : vos tâches assignées n’apparaîtront pas ici. Demandez à l’administrateur de créer votre accès depuis la fiche employé.')}
        </div>
      )}

      {/* À traiter → renvoie vers le + (écran Nouvelle saisie) */}
      {totalTodo > 0 ? (
        <Link to="/nouvelle" className="cta-card">
          <div className="cta-body">
            <div className="cta-title">{t(':count à traiter', { count: totalTodo })}</div>
            <div className="cta-breakdown">
              {breakdown.filter((b) => b.count > 0).map((b) => (
                <span key={b.label} className="cta-chip">{b.count} {b.label}</span>
              ))}
            </div>
          </div>
          <span className="cta-plus" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>
          </span>
        </Link>
      ) : batches.length > 0 ? (
        <div className="ok-card">✓ {t("Tout est à jour pour aujourd'hui.")}</div>
      ) : (
        <div className="ok-card ok-muted">
          {t('Aucun lot local — la synchronisation les rapatriera au premier passage réseau.')}
        </div>
      )}

      {/* Aperçu des dernières alertes */}
      {alerts.length > 0 && (
        <section>
          <div className="section-head">
            <h3>{t('Dernières alertes')}</h3>
            <Link to="/alertes" className="section-link">{t('Voir tout')}</Link>
          </div>
          {alerts.map((n) => (
            <div key={n.id} className={`notif-card ${SEVERITY_CLASS[n.severity] ?? 'notif-normal'} ${n.read_at ? 'notif-read' : ''}`}>
              <span className="notif-dot" aria-hidden="true" />
              <div className="notif-body">
                <span className="task-title">{n.title}</span>
                <span className="task-meta">{new Date(n.created_at).toLocaleString(dateLocale())}</span>
              </div>
            </div>
          ))}
        </section>
      )}

      <div style={{ height: 64 }} aria-hidden="true" />
    </div>
  )
}
