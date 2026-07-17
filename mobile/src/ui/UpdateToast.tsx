/**
 * Toast « Mise à jour disponible » — pendant mobile du bandeau SW du web.
 * Apparaît quand le service worker signale une nouvelle version ; « Recharger »
 * l'applique, la croix reporte au prochain lancement.
 */
import { useEffect, useState } from 'react'
import { onNeedRefresh, applyUpdate } from '../app/swUpdate'
import { t } from '../i18n'

export function UpdateToast() {
  const [show, setShow] = useState(false)

  useEffect(() => onNeedRefresh(() => setShow(true)), [])

  if (!show) return null

  return (
    <div className="update-toast" role="status" aria-live="polite">
      <span className="update-toast__ico" aria-hidden="true">🔄</span>
      <span className="update-toast__label">{t('Mise à jour disponible')}</span>
      <button type="button" className="update-toast__btn" onClick={() => applyUpdate()}>
        {t('Recharger')}
      </button>
      <button type="button" className="update-toast__close" aria-label={t('Fermer')} onClick={() => setShow(false)}>
        ✕
      </button>
    </div>
  )
}
