/**
 * Centre de notifications — miroir local (lisible hors-ligne) de la cloche
 * web : alertes météo, pics de mortalité, stocks bas, tâches dues.
 * Marquage lu optimiste : local d'abord, serveur quand le réseau le permet.
 */
import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { db } from '../../offline/db'
import type { ApiNotification } from '../../api/types'

const SEVERITY_ICON: Record<string, string> = {
  critical: '🔴',
  warning: '🟠',
  normal: '🔵',
}

export function NotificationsScreen() {
  const [notifications, setNotifications] = useState<ApiNotification[]>([])

  async function refresh() {
    setNotifications(await db.notifications.orderBy('created_at').reverse().toArray())
  }

  useEffect(() => {
    void refresh()
    const onUpdate = () => void refresh()
    window.addEventListener('notifications:updated', onUpdate)
    return () => window.removeEventListener('notifications:updated', onUpdate)
  }, [])

  async function markAllRead() {
    const now = new Date().toISOString()
    await db.notifications.toCollection().modify({ read_at: now })
    await refresh()
    window.dispatchEvent(new CustomEvent('notifications:updated'))
    if (navigator.onLine) {
      try {
        await api.markAllNotificationsRead()
      } catch {
        // Hors-ligne au mauvais moment : le prochain refreshNotifications
        // réalignera l'état local sur le serveur.
      }
    }
  }

  const unread = notifications.filter((n) => !n.read_at).length

  return (
    <div className="screen">
      <h2>Alertes</h2>

      {unread > 0 && (
        <button type="button" className="btn-secondary" onClick={() => void markAllRead()}>
          Tout marquer lu ({unread})
        </button>
      )}

      {notifications.length === 0 && (
        <p className="muted">Aucune alerte — elles arrivent à chaque synchronisation.</p>
      )}

      {notifications.map((n) => (
        <div key={n.id} className={`notif-card ${n.read_at ? 'notif-read' : ''}`}>
          <span className="notif-icon">{SEVERITY_ICON[n.severity] ?? '🔵'}</span>
          <div className="notif-body">
            <span className="task-title">{n.title}</span>
            <span className="muted">{n.message}</span>
            <span className="task-meta">{new Date(n.created_at).toLocaleString('fr-FR')}</span>
          </div>
        </div>
      ))}
    </div>
  )
}
