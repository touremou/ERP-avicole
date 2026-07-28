/**
 * ABONNEMENT AU PUSH NAVIGATEUR.
 *
 * Le centre d'alertes ne se remplit qu'à la synchronisation : le technicien doit
 * OUVRIR l'application pour voir qu'une mortalité a franchi le seuil. Le push est
 * le seul canal qui atteigne le terrain sans ça.
 *
 * TROIS CONDITIONS, et il faut savoir laquelle manque pour le dire à l'utilisateur
 * plutôt que de lui afficher un échec muet :
 *
 *   1. le navigateur sait faire (Service Worker + Push API) ;
 *   2. le serveur a une clef publique VAPID ;
 *   3. l'utilisateur a ACCORDÉ l'autorisation — et cette demande doit partir d'un
 *      geste de sa part, sinon les navigateurs la refusent d'office.
 *
 * SUR IPHONE, l'application doit avoir été ajoutée à l'écran d'accueil
 * (iOS 16.4+) : Safari ne délivre pas de push à un simple onglet. Sur Android
 * Chrome, l'onglet suffit.
 */
import { api } from '../api/client'

export type PushState =
  | 'unsupported'      // navigateur trop ancien, ou iOS hors écran d'accueil
  | 'not_configured'   // le serveur n'a pas de clef VAPID
  | 'denied'           // l'utilisateur a refusé (dans les réglages du navigateur)
  | 'off'              // possible, pas encore activé
  | 'on'               // cet appareil est abonné

export interface PushStatus {
  state: PushState
  /** Détail affichable quand ça ne peut pas marcher. */
  reason?: string
}

/** Le navigateur sait-il faire du push ? */
export function pushSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

/**
 * iOS ne délivre le push qu'à une application installée sur l'écran d'accueil.
 * On le détecte pour pouvoir l'EXPLIQUER : sans ce message, l'utilisateur croit
 * l'application défaillante.
 */
export function iosNeedsInstall(): boolean {
  const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent)
  const standalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    (window.navigator as unknown as { standalone?: boolean }).standalone === true

  return isIos && !standalone
}

/**
 * La clef VAPID doit être transmise au navigateur en octets bruts.
 * On alloue explicitement un ArrayBuffer : la signature de `applicationServerKey`
 * n'accepte pas un Uint8Array adossé à un SharedArrayBuffer.
 */
function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(normalized)
  const output = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i)
  return output
}

/** Étiquette d'appareil, pour que le promoteur sache QUEL téléphone est abonné. */
function deviceLabel(): string {
  const ua = navigator.userAgent
  const platform = /Android/.test(ua)
    ? 'Android'
    : /iPad|iPhone|iPod/.test(ua)
      ? 'iPhone/iPad'
      : /Windows/.test(ua)
        ? 'Windows'
        : /Mac/.test(ua)
          ? 'Mac'
          : 'Appareil'

  return `${platform} — ${navigator.language}`
}

/** État courant, sans rien demander à l'utilisateur. */
export async function pushStatus(): Promise<PushStatus> {
  if (!pushSupported()) {
    return {
      state: 'unsupported',
      reason: iosNeedsInstall()
        ? "Sur iPhone, ajoutez d'abord l'application à l'écran d'accueil (Partager → Sur l'écran d'accueil)."
        : 'Ce navigateur ne gère pas les notifications.',
    }
  }

  if (Notification.permission === 'denied') {
    return {
      state: 'denied',
      reason: 'Les notifications sont bloquées dans les réglages du navigateur pour ce site.',
    }
  }

  let configured = false
  try {
    configured = (await api.pushKey()).configured
  } catch {
    // Hors réseau : on ne sait pas. On n'affirme pas que c'est cassé.
    configured = true
  }

  if (!configured) {
    return {
      state: 'not_configured',
      reason: "Le serveur n'a pas encore de clef de notification (php artisan push:generate-keys).",
    }
  }

  const registration = await navigator.serviceWorker.getRegistration()
  const subscription = await registration?.pushManager.getSubscription()

  return { state: subscription ? 'on' : 'off' }
}

/**
 * Active le push sur CET appareil. À appeler depuis un geste utilisateur : les
 * navigateurs refusent une demande d'autorisation non sollicitée.
 */
export async function enablePush(): Promise<PushStatus> {
  if (!pushSupported()) return pushStatus()

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') {
    return {
      state: permission === 'denied' ? 'denied' : 'off',
      reason: 'Autorisation non accordée.',
    }
  }

  const { configured, public_key: publicKey } = await api.pushKey()
  if (!configured || !publicKey) {
    return { state: 'not_configured', reason: "Le serveur n'a pas de clef de notification." }
  }

  const registration = await navigator.serviceWorker.ready

  // Un abonnement existant peut porter une AUTRE clef (rotation côté serveur) :
  // il ne recevrait alors plus rien. On le remplace au lieu de le réutiliser.
  const existing = await registration.pushManager.getSubscription()
  if (existing) await existing.unsubscribe()

  const subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey),
  })

  const raw = subscription.toJSON() as {
    endpoint?: string
    keys?: { p256dh?: string; auth?: string }
  }

  await api.pushSubscribe({
    endpoint: raw.endpoint ?? '',
    keys: { p256dh: raw.keys?.p256dh ?? '', auth: raw.keys?.auth ?? '' },
    device_label: deviceLabel(),
  })

  return { state: 'on' }
}

/**
 * Coupe le push sur cet appareil : on se désabonne côté navigateur ET on retire
 * la ligne côté serveur. N'en faire qu'un laisserait des envois dans le vide ou
 * une bannière qu'on ne peut plus arrêter.
 */
export async function disablePush(): Promise<PushStatus> {
  const registration = await navigator.serviceWorker.getRegistration()
  const subscription = await registration?.pushManager.getSubscription()

  if (subscription) {
    const endpoint = subscription.endpoint
    await subscription.unsubscribe()
    try {
      await api.pushUnsubscribe(endpoint)
    } catch {
      // Hors réseau : la prochaine tentative d'envoi nettoiera l'abonnement mort
      // (410 du fournisseur → suppression côté serveur).
    }
  }

  return { state: 'off' }
}
