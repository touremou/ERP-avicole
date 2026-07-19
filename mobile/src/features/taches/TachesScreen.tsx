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
import { db, getMeta } from '../../offline/db'
import { onSyncChange, enqueue } from '../../offline/sync'
import { safeLoad } from '../../offline/safeLoad'
import { t } from '../../i18n'
import { FilterChips } from '../../ui/FilterChips'
import { ExportButton } from '../../ui/ExportButton'
import { toCsv, exportOrShare, dateStamp } from '../../ui/exportShare'
import { TaskProofModal } from './TaskProofModal'
import type { RefTask, TaskSummary } from '../../api/types'

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

const PRIORITY_LABEL: Record<string, string> = {
  basse: 'Basse', normale: 'Normale', haute: 'Haute', critique: 'Critique',
}
const STATUS_LABEL: Record<string, string> = {
  a_faire: 'À faire', en_cours: 'En cours', en_retard: 'En retard', fait: 'Fait',
}

export function TachesScreen() {
  const { me } = useAuth()
  const hasEmployee = me?.scope.employee_id != null

  const [tasks, setTasks] = useState<RefTask[]>([])
  const [doneToday, setDoneToday] = useState(0)
  const [win, setWin] = useState('week')
  const [cat, setCat] = useState('all')
  const [showForm, setShowForm] = useState(false)
  const [title, setTitle] = useState('')
  const [category, setCategory] = useState<string>('controle')
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [priority, setPriority] = useState('normale')
  const [saving, setSaving] = useState(false)
  const [proofTask, setProofTask] = useState<RefTask | null>(null)

  useEffect(() => {
    const load = async () => {
      const all = await db.tasks.orderBy('scheduled_date').toArray()
      setTasks(all.filter((task) => task.status !== 'fait'))
      const summary = await getMeta<TaskSummary>('tasks_summary')
      setDoneToday(summary?.done_today ?? 0)
    }
    void safeLoad('taches', load)
    const onUpdate = () => void safeLoad('taches', load)
    window.addEventListener('tasks:updated', onUpdate)
    const off = onSyncChange(() => void safeLoad('taches', load))
    return () => {
      window.removeEventListener('tasks:updated', onUpdate)
      off()
    }
  }, [])

  const todayStr = new Date().toISOString().slice(0, 10)

  // Récap « ma journée » — dérivé de la liste (donc juste même hors-ligne) ;
  // « fait aujourd'hui » vient du dernier récap serveur en cache.
  const kpis = useMemo(() => ({
    today: tasks.filter((task) => task.scheduled_date === todayStr).length,
    overdue: tasks.filter((task) => task.scheduled_date < todayStr).length,
    upcoming: tasks.filter((task) => task.scheduled_date > todayStr).length,
    high: tasks.filter((task) => task.priority === 'haute' || task.priority === 'critique').length,
  }), [tasks, todayStr])

  // Fenêtre de consultation (le récap au-dessus reste global, lui) : tout
  // (7 jours), aujourd'hui seul, ou seulement le retard à rattraper.
  const periodChips = useMemo(() => [
    { key: 'week', label: t('7 jours'), count: tasks.length },
    { key: 'today', label: t("Aujourd'hui"), count: tasks.filter((task) => task.scheduled_date === todayStr).length },
    { key: 'overdue', label: t('En retard'), count: tasks.filter((task) => task.scheduled_date < todayStr).length },
  ], [tasks, todayStr])

  const inWindow = useMemo(() => {
    if (win === 'today') return tasks.filter((task) => task.scheduled_date === todayStr)
    if (win === 'overdue') return tasks.filter((task) => task.scheduled_date < todayStr)
    return tasks
  }, [tasks, win, todayStr])

  const catChips = useMemo(() => {
    const cats = [...new Set(inWindow.map((task) => task.category).filter(Boolean))]
    return [
      { key: 'all', label: t('Toutes'), count: inWindow.length },
      ...cats.map((c) => ({ key: c, label: `${CATEGORY_ICON[c] ?? '📌'} ${t(c)}`, count: inWindow.filter((task) => task.category === c).length })),
    ]
  }, [inWindow])

  const visible = useMemo(
    () => (cat === 'all' ? inWindow : inWindow.filter((task) => task.category === cat)),
    [inWindow, cat],
  )

  const groups = useMemo(() => {
    const overdue = visible.filter((task) => task.scheduled_date < todayStr)
    const today = visible.filter((task) => task.scheduled_date === todayStr)
    const upcoming = visible.filter((task) => task.scheduled_date > todayStr)
    return [
      { key: 'overdue', label: t('En retard'), items: overdue, cls: 'task-overdue' },
      { key: 'today', label: t("Aujourd'hui"), items: today, cls: '' },
      { key: 'upcoming', label: t('À venir'), items: upcoming, cls: '' },
    ].filter((group) => group.items.length > 0)
  }, [visible, todayStr])

  // Verrou anti-doublon : prendre une tâche (optimiste). Elle passe « en cours
  // par moi » localement ; le serveur tranche à la synchro (2ᵉ preneur → « déjà
  // prise »).
  async function startTask(task: RefTask) {
    if (task.id < 0 || task.locked) return
    await enqueue('task.start', { task_id: task.id }, t('Tâche prise : :title', { title: task.title }))
    await db.tasks.update(task.id, { status: 'en_cours', claimed_by_me: true, locked: false })
    window.dispatchEvent(new CustomEvent('tasks:updated'))
  }

  async function releaseTask(task: RefTask) {
    if (task.id < 0) return
    await enqueue('task.release', { task_id: task.id }, t('Tâche libérée : :title', { title: task.title }))
    await db.tasks.update(task.id, { status: 'a_faire', claimed_by_me: false })
    window.dispatchEvent(new CustomEvent('tasks:updated'))
  }

  async function completeTask(task: RefTask) {
    // Une tâche pas encore synchronisée (id temporaire négatif) n'a pas d'id
    // serveur : on ne peut pas la clôturer tant qu'elle n'est pas remontée.
    if (task.id < 0) return
    // Preuve d'exécution requise → passer par la modale (photo/valeur).
    if (task.proof_type === 'photo' || task.proof_type === 'valeur') {
      setProofTask(task)
      return
    }
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

  function handleExport() {
    const csv = toCsv(
      [t('Intitulé'), t('Catégorie'), t('Priorité'), t('Échéance'), t('Heure'), t('Statut')],
      visible.map((task) => [
        task.title, t(task.category), t(PRIORITY_LABEL[task.priority ?? 'normale'] ?? task.priority ?? ''),
        task.scheduled_date, task.scheduled_time?.slice(0, 5) ?? '', t(STATUS_LABEL[task.status] ?? task.status),
      ]),
    )
    void exportOrShare(`taches_${win}_${dateStamp()}.csv`, csv, t('Mes tâches'))
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

      <div className="kpi-grid">
        <div className="kpi"><div className="kpi-val">{kpis.today}</div><div className="kpi-lab">{t("Aujourd'hui")}</div></div>
        {kpis.overdue > 0 && <div className="kpi kpi--alert"><div className="kpi-val">{kpis.overdue}</div><div className="kpi-lab">{t('En retard')}</div></div>}
        {kpis.high > 0 && <div className="kpi"><div className="kpi-val">{kpis.high}</div><div className="kpi-lab">{t('Prioritaires')}</div></div>}
        <div className="kpi"><div className="kpi-val">{doneToday}</div><div className="kpi-lab">{t('Faites aujourd’hui')}</div></div>
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

      <FilterChips options={periodChips} active={win} onChange={(value) => { setWin(value); setCat('all') }} />
      {catChips.length > 1 && <FilterChips options={catChips} active={cat} onChange={setCat} />}
      <ExportButton onExport={handleExport} disabled={visible.length === 0} />

      {groups.length === 0 ? (
        <div className="ok-card">✓ {win === 'week' && cat === 'all' ? t('Aucune tâche en cours. Bonne journée !') : t('Aucune tâche pour ce filtre.')}</div>
      ) : (
        groups.map((group) => (
          <section key={group.key}>
            <div className="section-head"><h3 className={group.cls}>{group.label}</h3><span className="section-count">{group.items.length}</span></div>
            {group.items.map((task) => (
              <div key={task.id} className={`task-row ${task.locked ? 'task-locked' : ''}`}>
                <div className="task-row__body">
                  <span className="task-title">{CATEGORY_ICON[task.category] ?? '📌'} {task.title}</span>
                  <span className="task-meta">
                    {task.scheduled_time ? task.scheduled_time.slice(0, 5) + ' · ' : ''}
                    {t(task.category)}
                    {task.is_pool && task.status === 'a_faire' ? ' · 🙌 ' + t('Libre') : ''}
                    {task.proof_type === 'photo' ? ' · 📸 ' + t('photo requise') : ''}
                    {task.proof_type === 'valeur' ? ' · 🔢 ' + t('valeur requise') : ''}
                    {task.locked ? ' · 🔒 ' + t('en cours par :name', { name: task.claimant_name ?? '—' }) : ''}
                    {task.id < 0 ? ' · ' + t('à synchroniser') : ''}
                  </span>
                </div>
                {task.locked ? (
                  <button type="button" className="task-done" disabled title={t('Verrouillée')}>🔒</button>
                ) : task.claimed_by_me ? (
                  <div className="task-actions">
                    <button type="button" className="task-ghost" onClick={() => void releaseTask(task)}>{t('Libérer')}</button>
                    <button type="button" className="task-done" disabled={task.id < 0} onClick={() => void completeTask(task)}>✓ {t('Terminer')}</button>
                  </div>
                ) : (
                  <button type="button" className="task-start" disabled={task.id < 0} onClick={() => void startTask(task)}>▶ {t('Prendre')}</button>
                )}
              </div>
            ))}
          </section>
        ))
      )}

      {proofTask && (
        <TaskProofModal
          task={proofTask}
          onDone={() => setProofTask(null)}
          onCancel={() => setProofTask(null)}
        />
      )}
    </div>
  )
}
