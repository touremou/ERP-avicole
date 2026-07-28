/*
 * PUSH NAVIGATEUR — la partie qui tourne même application FERMÉE.
 *
 * Ce fichier est importé par le service worker généré par Workbox
 * (workbox.importScripts). On ne passe pas en `injectManifest` pour ça : la
 * stratégie de cache actuelle marche, et la réécrire pour ajouter deux écouteurs
 * serait un risque pour rien.
 *
 * Deux événements, et rien d'autre :
 *
 *   push              → afficher la bannière. C'est le seul moment où le code
 *                       tourne sans que personne n'ait ouvert l'app.
 *   notificationclick → ouvrir l'écran visé, en RÉUTILISANT l'onglet déjà ouvert
 *                       s'il y en a un. Sans ça, chaque alerte cliquée empilerait
 *                       une nouvelle fenêtre.
 */

const SEVERITY_ICON = {
  critique: '🔴',
  attention: '⚠️',
  normal: '🔔',
}

self.addEventListener('push', (event) => {
  // Un push sans données ne doit pas faire échouer l'écouteur : certains
  // fournisseurs envoient des pings de maintien de connexion.
  let payload = {}
  try {
    payload = event.data ? event.data.json() : {}
  } catch (e) {
    payload = { title: 'Biocrest', body: '' }
  }

  const severity = payload.severity || 'normal'
  const icon = SEVERITY_ICON[severity] || SEVERITY_ICON.normal

  event.waitUntil(
    self.registration.showNotification(`${icon} ${payload.title || 'Alerte'}`, {
      body: payload.body || '',
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      // Regroupe par type : trois alertes de mortalité ne laissent qu'une
      // bannière, la plus récente. Un écran verrouillé saturé n'est plus lu.
      tag: payload.tag || 'biocrest',
      renotify: true,
      // Une alerte critique reste affichée jusqu'à ce qu'on la touche ; les
      // autres se referment d'elles-mêmes.
      requireInteraction: severity === 'critique',
      data: { url: payload.url || '/alertes' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const target = (event.notification.data && event.notification.data.url) || '/alertes'

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // Onglet déjà ouvert : on y navigue plutôt que d'en ouvrir un second.
      for (const client of clientList) {
        if ('focus' in client) {
          if ('navigate' in client) {
            return client.navigate(target).then((c) => (c ? c.focus() : undefined))
          }
          return client.focus()
        }
      }

      return self.clients.openWindow(target)
    }),
  )
})
