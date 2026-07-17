/**
 * Tâches — écran dédié (au-delà de l'aperçu de l'accueil).
 *
 * Liste des tâches de MON employé (déjà scopée serveur via /tasks), regroupées
 * par échéance (En retard / Aujourd'hui / À venir), avec complétion hors-ligne
 * (task.complete) et CRÉATION d'une tâche personnelle (task.create) — auto-
 * assignée à moi. Offline-first : la création est optimiste (id temporaire),
 * réconciliée par la prochaine synchro de /tasks.
 */
import { useEffect, useMemo, useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { db } from '../../offline/db'
import { onSyncChange, enqueue } from '../../offline/sync'
import { t } from '../../i18n'
import type { RefTask } from '../../api/types'

const CATEGORY_ICON: Record<string, string> = {
  alimentation: '🌾',
  collecte: '🥚',
  nettoyage: '🧽',
  sante: '🩺',
  controle: '📋',
  maintenance: '🔧',
  autre: '📌',
}

const CATEGORIES = ['controle', 'nettoyage', 'alimentation', 'sante', 'maintenance', 'autre'] as const

export function TachesScreen() {
  const { me } = useAuth()
  const hasEmployee = me?.scope.employee_id != null

  const [tasks, setTasks] = useState<RefTask[]>([])
  const [showForm, setShowForm] = useState(false)
  const [title, setTitle] = useState('')
  const [category, setCategory] = useState<string>('controle')
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [priority, setPriority] = useState('normale')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    const load = async () => {
      const all = await db.tasks.orderBy('scheduled_date').toArray()
      setTasks(all.filter((task) => task.status !== 'fait'))
    }
    void load()
    const onUpdate = () => void load()
    window.addEventListener('tasks:updated', onUpdate)
    const off = onSyncChange(() => void load())
    return () => {
      window.removeEventListener('tasks:updated', onUpdate)
      off()
    }
  }, [])

  const todayStr = new Date().toISOString().slice(0, 10)

  const groups = useMemo(() => {
    const overdue = tasks.filter((task) => task.scheduled_date < todayStr)
    const today = tasks.filter((task) => task.scheduled_date === todayStr)
    const upcoming = tasks.filter((task) => task.scheduled_date > todayStr)
    return [
      { key: 'overdue', label: t('En retard'), items: overdue, cls: 'task-overdue' },
      { key: 'today', label: t("Aujourd'hui"), items: today, cls: '' },
      { key: 'upcoming', label: t('À venir'), items: upcoming, cls: '' },
    ].filter((group) => group.items.length > 0)
  }, [tasks, todayStr])

  async function completeTask(task: RefTask) {
    // Une tâche pas encore synchronisée (id temporaire négatif) n'a pas d'id
    // serveur : on ne peut pas la clôturer tant qu'elle n'est pas remontée.
    if (task.id < 0) return
    await enqueue('task.complete', { task_id: task.id }, t('Tâche : :title', { title: task.title }))
    await db.tasks.delete(task.id)
    window.dispatchEvent(new CustomEvent('tasks:updated'))
  }

  async function createTask(event: React.FormEvent) {
    event.preventDefault()
    if (!title.trim() || saving) return
    setSaving(true)
    try {
      await enqueue(
        'task.create',
        { title: title.trim(), category, scheduled_date: date, priority },
        t('Nouvelle tâche : :title', { title: title.trim() }),
      )
      // Optimiste : affichage immédiat avec un id temporaire (réconcilié au pull).
      const tempId = -Date.now()
      await db.tasks.put({
        id: tempId, title: title.trim(), category, priority, status: 'a_faire',
        scheduled_date: date, scheduled_time: null, batch_id: null, building_id: null, plot_id: null,
      })
      window.dispatchEvent(new CustomEvent('tasks:updated'))
      setTitle('')
      setPriority('normale')
      setShowForm(false)
    } finally {
      setSaving(false)
    }
  }

  if (!hasEmployee) {
    return (
      <div className="screen">
        <div className="welcome"><h2>{t('Mes tâches')}</h2></div>
        <div className="ok-card ok-muted">
          {t('ℹ️ Votre compte n’est pas rattaché à une fiche employé : vos tâches assignées n’apparaîtront pas ici. Demandez à l’administrateur de créer votre accès depuis la fiche employé.')}
        </div>
      </div>
    )
  }

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Mes tâches')} 📋</h2>
        <span className="welcome-sub">{t(':count tâche(s) en cours', { count: tasks.length })}</span>
      </div>

      <button type="button" className="btn-primary" onClick={() => setShowForm((value) => !value)}>
        {showForm ? t('Annuler') : '＋ ' + t('Nouvelle tâche')}
      </button>

      {showForm && (
        <form className="screen" onSubmit={createTask}>
          <label htmlFor="task-title">{t('Intitulé')}</label>
          <input id="task-title" type="text" value={title} onChange={(event) => setTitle(event.target.value)} required maxLength={255} placeholder={t('Ex. Vérifier les abreuvoirs B2')} />

          <label htmlFor="task-category">{t('Catégorie')}</label>
          <select id="task-category" value={category} onChange={(event) => setCategory(event.target.value)}>
            {CATEGORIES.map((key) => (
              <option key={key} value={key}>{CATEGORY_ICON[key]} {t(key)}</option>
            ))}
          </select>

          <label htmlFor="task-date">{t('Échéance')}</label>
          <input id="task-date" type="date" value={date} onChange={(event) => setDate(event.target.value)} required />

          <label htmlFor="task-priority">{t('Priorité')}</label>
          <select id="task-priority" value={priority} onChange={(event) => setPriority(event.target.value)}>
            <option value="basse">{t('Basse')}</option>
            <option value="normale">{t('Normale')}</option>
            <option value="haute">{t('Haute')}</option>
            <option value="critique">{t('Critique')}</option>
          </select>

          <button type="submit" className="btn-primary" disabled={saving || !title.trim()}>
            {saving ? t('Enregistrement…') : t('Créer la tâche')}
          </button>
        </form>
      )}

      {groups.length === 0 ? (
        <div className="ok-card">✓ {t('Aucune tâche en cours. Bonne journée !')}</div>
      ) : (
        groups.map((group) => (
          <section key={group.key}>
            <div className="section-head"><h3 className={group.cls}>{group.label}</h3><span className="section-count">{group.items.length}</span></div>
            {group.items.map((task) => (
              <div key={task.id} className="task-row">
                <div className="task-row__body">
                  <span className="task-title">{CATEGORY_ICON[task.category] ?? '📌'} {task.title}</span>
                  <span className="task-meta">
                    {task.scheduled_time ? task.scheduled_time.slice(0, 5) + ' · ' : ''}
                    {t(task.category)}
                    {task.id < 0 ? ' · ' + t('à synchroniser') : ''}
                  </span>
                </div>
                <button type="button" className="task-done" disabled={task.id < 0} onClick={() => void completeTask(task)}>
                  ✓ {t('Fait')}
                </button>
              </div>
            ))}
          </section>
        ))
      )}
    </div>
  )
}
