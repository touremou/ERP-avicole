/**
 * Appareils connectés — un token Sanctum = un appareil (device_name du login).
 * L'utilisateur liste ses appareils et en révoque un à distance ; le cas
 * critique terrain est le TÉLÉPHONE PERDU (couper l'accès sans changer le mot
 * de passe). L'appareil courant ne se révoque pas ici (voir « Se déconnecter »).
 *
 * En ligne uniquement : la liste des tokens n'a pas de sens hors-ligne et ne
 * se met pas en cache (données de session sensibles).
 */
import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { t, dateLocale } from '../../i18n'
import type { DeviceInfo } from '../../api/types'

export function AppareilsScreen() {
  const [devices, setDevices] = useState<DeviceInfo[]>([])
  const [loading, setLoading] = useState(true)
  const [offline, setOffline] = useState(!navigator.onLine)
  const [confirming, setConfirming] = useState<number | null>(null)
  const [revoking, setRevoking] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    if (!navigator.onLine) {
      setOffline(true)
      setLoading(false)
      return
    }
    try {
      const { devices } = await api.devices()
      setDevices(devices)
      setOffline(false)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('Une erreur est survenue.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  async function revoke(id: number) {
    setRevoking(id)
    setError(null)
    try {
      await api.revokeDevice(id)
      setConfirming(null)
      await load()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('Une erreur est survenue.'))
    } finally {
      setRevoking(null)
    }
  }

  function fmt(iso: string | null): string {
    if (!iso) return t('jamais')
    return new Date(iso).toLocaleString(dateLocale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
  }

  return (
    <div className="screen">
      <div className="welcome">
        <h2>{t('Appareils connectés')} 📱</h2>
        <span className="welcome-sub">{t('Révoquez un appareil perdu ou inutilisé.')}</span>
      </div>

      {error && <div className="ok-card notif-critical">{error}</div>}

      {loading ? (
        <div className="ok-card ok-muted">{t('Chargement…')}</div>
      ) : offline ? (
        <div className="ok-card ok-muted">{t('Connexion requise pour gérer les appareils.')}</div>
      ) : devices.length === 0 ? (
        <div className="ok-card">{t('Aucun appareil connecté.')}</div>
      ) : (
        devices.map((device) => (
          <div key={device.id} className="task-row">
            <div className="task-row__body">
              <span className="task-title">
                📱 {device.name}
                {device.current && <span className="pay-badge pay-paid"> {t('Cet appareil')}</span>}
              </span>
              <span className="task-meta">
                {t('Dernier accès')} : {fmt(device.last_used_at)}
              </span>
            </div>
            {!device.current && (
              confirming === device.id ? (
                <button type="button" className="btn-danger btn-slim" disabled={revoking === device.id} onClick={() => void revoke(device.id)}>
                  {revoking === device.id ? t('Révocation…') : t('Confirmer')}
                </button>
              ) : (
                <button type="button" className="btn-secondary btn-slim" onClick={() => setConfirming(device.id)}>
                  {t('Révoquer')}
                </button>
              )
            )}
          </div>
        ))
      )}
    </div>
  )
}
