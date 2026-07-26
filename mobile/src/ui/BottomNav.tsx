/** Bottom-nav — règle UX n°1 : actions principales en zone du pouce, 4-5 entrées max. */
import { useEffect, useState } from 'react'
import { NavLink } from 'react-router-dom'
import { useAuth } from '../app/AuthContext'
import { t } from '../i18n'
import { db } from '../offline/db'
import { allows, ROUTE_ACCESS } from '../offline/access'

export function BottomNav() {
  const { can, me } = useAuth()
  const [unread, setUnread] = useState(0)
  const [dueTasks, setDueTasks] = useState(0)

  // L'entrée « Tâches » suit EXACTEMENT le droit de la route (@owner : il faut
  // une fiche employé). Lire ROUTE_ACCESS plutôt que recopier la condition
  // empêche la divergence qui afficherait un onglet menant à « Accès refusé ».
  const canSeeTasks = allows(ROUTE_ACCESS['/taches'], {
    can,
    hasEmployee: me?.scope.employee_id != null,
  })

  useEffect(() => {
    const refresh = () =>
      void db.notifications.filter((n) => !n.read_at).count().then(setUnread)
    refresh()
    window.addEventListener('notifications:updated', refresh)
    return () => window.removeEventListener('notifications:updated', refresh)
  }, [])

  useEffect(() => {
    if (!canSeeTasks) return

    // Le badge ne compte que ce qui demande une action MAINTENANT : à faire
    // aujourd'hui ou en retard. Y mettre tout le prévisionnel afficherait un
    // nombre permanent que plus personne ne regarde.
    const refresh = () => {
      const today = new Date().toISOString().slice(0, 10)
      void db.tasks
        .filter((task) => task.status !== 'fait' && task.scheduled_date <= today)
        .count()
        .then(setDueTasks)
    }
    refresh()
    window.addEventListener('tasks:updated', refresh)
    return () => window.removeEventListener('tasks:updated', refresh)
  }, [canSeeTasks])

  return (
    <nav className="bottom-nav">
      <NavLink to="/" end>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
          <path d="M9 22V12h6v10" />
        </svg>
        <span>{t('Accueil')}</span>
      </NavLink>
      {canSeeTasks && (
        <NavLink to="/taches">
          <span className="nav-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              {/* Presse-papiers coché : la tâche à faire, pas une liste abstraite. */}
              <path d="M9 3h6a1 1 0 0 1 1 1v1H8V4a1 1 0 0 1 1-1z" />
              <path d="M16 5h1a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h1" />
              <path d="m9 14 2 2 4-4" />
            </svg>
            {dueTasks > 0 && <span className="nav-badge">{dueTasks > 9 ? '9+' : dueTasks}</span>}
          </span>
          <span>{t('Tâches')}</span>
        </NavLink>
      )}
      <NavLink to="/alertes">
        <span className="nav-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
          </svg>
          {unread > 0 && <span className="nav-badge">{unread > 9 ? '9+' : unread}</span>}
        </span>
        <span>{t('Alertes')}</span>
      </NavLink>
      <NavLink to="/mon-espace">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="8" r="4" />
          <path d="M4 21c0-4 4-6 8-6s8 2 8 6" />
        </svg>
        <span>{t('Mon espace')}</span>
      </NavLink>
    </nav>
  )
}
