/**
 * Session utilisateur — cache-first : le payload `me` (rôle, permissions,
 * scope) vit dans Dexie et se rafraîchit en arrière-plan quand le réseau le
 * permet. L'app démarre donc HORS-LIGNE avec la dernière session connue
 * (exigence « balle traçante » de la Phase 0).
 */
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from 'react'
import { api, clearSession } from '../api/client'
import { db, getMeta, setMeta } from '../offline/db'
import { adoptProfileLocale } from '../i18n'
import type { MeResponse, PermissionLevel } from '../api/types'

interface AuthContextValue {
  me: MeResponse | null
  loading: boolean
  login: (email: string, password: string, deviceName: string) => Promise<void>
  logout: () => Promise<void>
  /** Rafraîchit le payload `me` depuis le serveur (après édition du profil). */
  refreshMe: () => Promise<void>
  /** Gate hors-ligne : lit le cache de permissions. Le serveur re-vérifie au push. */
  can: (module: string, level: PermissionLevel) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<MeResponse | null>(null)
  const [loading, setLoading] = useState(true)

  // Restauration de session au démarrage (offline-safe) + refresh opportuniste.
  useEffect(() => {
    void (async () => {
      const cached = await getMeta<MeResponse>('me')
      if (cached) setMe(cached)
      setLoading(false)

      if (cached && navigator.onLine) {
        try {
          const fresh = await api.me()
          await setMeta('me', fresh)
          // Recale la ferme courante sur celle RÉSOLUE par le serveur : si le
          // X-Farm-Id local était périmé (ferme renommée/recréée), le serveur a
          // replié sur la ferme par défaut → on adopte ce farm_id pour ne plus
          // envoyer l'ancien (auto-guérison du contexte multi-ferme).
          if (fresh.scope.farm_id) await setMeta('farm_id', fresh.scope.farm_id)
          await adoptProfileLocale(fresh.user.locale)
          setMe(fresh)
        } catch {
          // Hors-ligne ou token expiré (géré par l'event auth:expired).
        }
      }
    })()

    const onExpired = () => setMe(null)
    window.addEventListener('auth:expired', onExpired)
    return () => window.removeEventListener('auth:expired', onExpired)
  }, [])

  const login = useCallback(async (email: string, password: string, deviceName: string) => {
    const { token } = await api.login(email, password, deviceName)
    await setMeta('token', token)

    const fresh = await api.me()
    await setMeta('me', fresh)
    if (fresh.scope.farm_id) await setMeta('farm_id', fresh.scope.farm_id)
    await adoptProfileLocale(fresh.user.locale)
    setMe(fresh)
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.logout()
    } catch {
      // Déjà déconnecté côté serveur ou hors-ligne : on purge quand même.
    }
    await clearSession()
    // Les données de référence restent (autre utilisateur de la même ferme
    // possible) mais les saisies personnelles non poussées sont conservées :
    // l'outbox appartient à l'appareil, elle repartira à la prochaine session.
    await db.meta.delete('last_pull_at')
    setMe(null)
  }, [])

  const refreshMe = useCallback(async () => {
    const fresh = await api.me()
    await setMeta('me', fresh)
    if (fresh.scope.farm_id) await setMeta('farm_id', fresh.scope.farm_id)
    await adoptProfileLocale(fresh.user.locale)
    setMe(fresh)
  }, [])

  const can = useCallback(
    (module: string, level: PermissionLevel) =>
      me?.permissions[module]?.includes(level) ?? false,
    [me],
  )

  return (
    <AuthContext.Provider value={{ me, loading, login, logout, refreshMe, can }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth doit être utilisé sous <AuthProvider>')
  return ctx
}
