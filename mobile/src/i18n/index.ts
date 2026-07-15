/**
 * i18n terrain — même modèle que le lang/*.json de Laravel : la chaîne
 * SOURCE est le français (langue de référence du projet), le dictionnaire
 * anglais mappe fr → en. Clé absente → repli sur le français, l'app ne
 * casse jamais pour une traduction manquante.
 *
 * Résolution de la langue (ordre de priorité) :
 *   1. choix manuel dans « Mon espace » (meta 'locale') ;
 *   2. langue du profil web (users.locale via /auth/me) ;
 *   3. français.
 */
import { getMeta, setMeta } from '../offline/db'
import { en } from './en'

export type Locale = 'fr' | 'en'
export const SUPPORTED_LOCALES: Locale[] = ['fr', 'en']

let current: Locale = 'fr'
const listeners = new Set<() => void>()

/** À appeler AVANT le rendu React (main.tsx) : restaure le choix persisté. */
export async function initLocale(): Promise<void> {
  const saved = await getMeta<Locale>('locale')
  if (saved && SUPPORTED_LOCALES.includes(saved)) current = saved
}

export function getLocale(): Locale {
  return current
}

/** Choix manuel (Mon espace) — persisté, prioritaire sur le profil. */
export async function setLocale(locale: Locale): Promise<void> {
  if (!SUPPORTED_LOCALES.includes(locale) || locale === current) return
  current = locale
  await setMeta('locale', locale)
  listeners.forEach((l) => l())
}

/**
 * Langue du profil web (me.user.locale) : adoptée seulement si l'utilisateur
 * n'a pas fait de choix manuel sur CET appareil.
 */
export async function adoptProfileLocale(locale: string | null | undefined): Promise<void> {
  if (!locale || !SUPPORTED_LOCALES.includes(locale as Locale)) return
  if (await getMeta<Locale>('locale')) return // choix manuel → prioritaire
  if (locale === current) return
  current = locale as Locale
  listeners.forEach((l) => l())
}

/** Abonnement (useSyncExternalStore) — le Shell se re-rend au changement. */
export function subscribeLocale(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/**
 * Traduit une chaîne source française. Interpolation par :placeholders,
 * comme Laravel : t('Collecte :code', { code: batch.code }).
 */
export function t(fr: string, params?: Record<string, string | number>): string {
  let out = current === 'en' ? (en[fr] ?? fr) : fr
  if (params) {
    for (const [key, value] of Object.entries(params)) {
      out = out.replaceAll(`:${key}`, String(value))
    }
  }
  return out
}

/** Locale BCP-47 pour toLocaleDateString / toLocaleString. */
export function dateLocale(): string {
  return current === 'en' ? 'en-GB' : 'fr-FR'
}
