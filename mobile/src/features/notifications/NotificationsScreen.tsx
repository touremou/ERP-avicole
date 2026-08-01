/**
 * Centre de notifications — miroir local (lisible hors-ligne) de la cloche
 * web : alertes météo, pics de mortalité, stocks bas, tâches dues.
 * Marquage lu optimiste : local d'abord, serveur quand le réseau le permet.
 */
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../../api/client'
import { db } from '../../offline/db'
import { accessForPath, allows } from '../../offline/access'
import { useAuth } from '../../app/AuthContext'
import { safeLoad } from '../../offline/safeLoad'
import { dateLocale, t } from '../../i18n'
import { notifIcon } from './notifIcon'
import type { ApiNotification } from '../../api/types'

const SEVERITY_CLASS: Record<string, string> = {
  critical: 'notif-critical',
  warning: 'notif-warning',
  normal: 'notif-normal',
}

export function NotificationsScreen() {
  const navigate = useNavigate()
  const { can, me } = useAuth()
  const [refused, setRefused] = useState('')
  const [notifications, setNotifications] = useState<ApiNotification[]>([])

  async function refresh() {
    setNotifications(await db.notifications.orderBy('created_at').reverse().toArray())
  }

  useEffect(() => {
    void safeLoad('alertes', refresh)
    const onUpdate = () => void safeLoad('alertes', refresh)
    window.addEventListener('notifications:updated', onUpdate)
    return () => window.removeEventListener('notifications:updated', onUpdate)
  }, [])

  /**
   * Ouvrir une alerte : la marquer lue, puis aller où elle mène.
   *
   * Les cartes n'étaient pas cliquables du tout — une alerte annonçait une
   * action à faire et laissait chercher l'écran. Le serveur fournit désormais
   * l'adresse du TERRAIN (pas la route web, que ce routeur ignore).
   *
   * Sans adresse — alerte d'avant ce correctif — on marque lu et on reste : un
   * saut vers l'accueil se lirait comme une erreur de manipulation.
   */
  async function open(n: ApiNotification) {
    if (!n.read_at) {
      await db.notifications.update(n.id, { read_at: new Date().toISOString() })
      await refresh()
      window.dispatchEvent(new CustomEvent('notifications:updated'))
    }

    if (!n.url || n.url === '/alertes') {
      return   // on y est déjà : naviguer ne montrerait rien de neuf
    }

    // LE DROIT SE VÉRIFIE AVANT DE NAVIGUER. Une alerte part à tous les abonnés
    // d'un type ; l'écran qui la traite, lui, est réservé. Sans ce contrôle, le
    // clic menait à « Accès refusé » — un mur qui se lit comme une panne alors
    // que c'est une règle. On le DIT sur place, et l'alerte reste lisible.
    const spec = accessForPath(n.url)

    if (spec === null) {
      setRefused(t("Cette alerte ne renvoie à aucun écran de l'application terrain."))
      return
    }

    if (!allows(spec, { can, hasEmployee: me?.scope.employee_id != null })) {
      setRefused(t("Vous n'avez pas accès à l'écran qui traite cette alerte. Prévenez la personne concernée."))
      return
    }

    setRefused('')
    navigate(n.url)
  }

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
      <h2>{t('Alertes')}</h2>

      {unread > 0 && (
        <button type="button" className="btn-secondary" onClick={() => void markAllRead()}>
          {t('Tout marquer lu (:count)', { count: unread })}
        </button>
      )}

      {refused !== '' && <p className="notif-refused">{refused}</p>}

      {notifications.length === 0 && (
        <p className="muted">{t('Aucune alerte — elles arrivent à chaque synchronisation.')}</p>
      )}

      {notifications.map((n) => (
        <div
          key={n.id}
          role="button"
          tabIndex={0}
          onClick={() => void open(n)}
          onKeyDown={(event) => { if (event.key === 'Enter') void open(n) }}
          className={`notif-card ${SEVERITY_CLASS[n.severity] ?? 'notif-normal'} ${n.read_at ? 'notif-read' : ''}`}>
          <span className="notif-avatar" aria-hidden="true">{notifIcon(n.type, n.severity)}</span>
          <div className="notif-body">
            <span className="task-title">{n.title}</span>
            <span className="muted">{n.message}</span>
            <span className="task-meta">{new Date(n.created_at).toLocaleString(dateLocale())}</span>
          </div>
        </div>
      ))}
    </div>
  )
}
