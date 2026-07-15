/** Bottom-nav — règle UX n°1 : actions principales en zone du pouce, 4-5 entrées max. */
import { useEffect, useState } from 'react'
import { NavLink } from 'react-router-dom'
import { t } from '../i18n'
import { db } from '../offline/db'

export function BottomNav() {
  const [unread, setUnread] = useState(0)

  useEffect(() => {
    const refresh = () =>
      void db.notifications.filter((n) => !n.read_at).count().then(setUnread)
    refresh()
    window.addEventListener('notifications:updated', refresh)
    return () => window.removeEventListener('notifications:updated', refresh)
  }, [])

  return (
    <nav className="bottom-nav">
      <NavLink to="/" end>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
          <path d="M9 22V12h6v10" />
        </svg>
        <span>{t('Accueil')}</span>
      </NavLink>
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
