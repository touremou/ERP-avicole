/**
 * Enregistrement du service worker en mode « prompt » : quand une nouvelle
 * version est prête, on prévient l'UI (toast « Recharger ») au lieu de
 * recharger en silence. Si l'utilisateur ignore le toast, la version s'active
 * quand même au prochain lancement complet — on garde donc « toujours à jour »
 * sans imposer un rechargement au milieu d'une saisie terrain.
 */
import { registerSW } from 'virtual:pwa-register'

let updateSW: ((reloadPage?: boolean) => Promise<void>) | null = null
const listeners = new Set<() => void>()

export function initSwUpdate(): void {
  updateSW = registerSW({
    onNeedRefresh() {
      listeners.forEach((listener) => listener())
    },
  })
}

/** S'abonne à « une mise à jour est prête ». Renvoie la fonction de désinscription. */
export function onNeedRefresh(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** Applique la mise à jour en attente et recharge la page. */
export function applyUpdate(): void {
  void updateSW?.(true)
}
