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
        <span className="nav-icon">🏠</span>
        <span>{t('Accueil')}</span>
      </NavLink>
      <NavLink to="/alertes">
        <span className="nav-icon">
          🔔{unread > 0 && <span className="nav-badge">{unread > 9 ? '9+' : unread}</span>}
        </span>
        <span>{t('Alertes')}</span>
      </NavLink>
      <NavLink to="/mon-espace">
        <span className="nav-icon">👤</span>
        <span>{t('Mon espace')}</span>
      </NavLink>
    </nav>
  )
}
