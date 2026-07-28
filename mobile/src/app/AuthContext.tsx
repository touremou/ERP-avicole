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
import { clearPersonalData, db, getMeta, setMeta } from '../offline/db'
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
    // QUI était connecté avant ? Sur un téléphone de service qui passe de main en
    // main, le compte change sans qu'une déconnexion propre ait eu lieu (batterie
    // vide, application tuée).
    const previous = (await getMeta<MeResponse>('me'))?.user.id

    const { token } = await api.login(email, password, deviceName)
    await setMeta('token', token)

    const fresh = await api.me()

    // CHANGEMENT DE COMPTE : on purge les miroirs PERSONNELS du précédent —
    // alertes et tâches assignées. Il les voyait sinon s'afficher chez le suivant,
    // alors que le principe, côté web, est qu'on ne voit que ce qui nous concerne.
    //
    // L'historique de saisie et l'outbox RESTENT, marqués de leur auteur : ils ne
    // sont montrés qu'à lui et ne partiront pas au nom du nouveau venu
    // (cf. clearPersonalData et myPendingOperations).
    if (previous && previous !== fresh.user.id) {
      await clearPersonalData()
    }

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

    // Les données de RÉFÉRENCE restent (le suivant travaille sur la même ferme).
    // Les miroirs personnels partent : alertes et tâches assignées, retéléchargés
    // à la première synchronisation du suivant.
    //
    // L'historique et l'outbox restent, marqués de leur auteur : on ne détruit pas
    // du travail de terrain, et il n'est montré qu'à celui qui l'a saisi.
    await clearPersonalData()
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
