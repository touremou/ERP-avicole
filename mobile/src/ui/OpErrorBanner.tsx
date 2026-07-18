/**
 * OpErrorBanner — affiche les erreurs de validation d'une saisie rejetée AVANT
 * mise en file (événement 'op:rejected' émis par enqueue). Toast global : tout
 * écran de saisie bénéficie du retour sans code dédié. Auto-masqué, dismissable.
 */
import { useEffect, useState } from 'react'
import { t } from '../i18n'

export function OpErrorBanner() {
  const [errors, setErrors] = useState<string[]>([])

  useEffect(() => {
    const on = (event: Event) => {
      const detail = (event as CustomEvent<{ errors: string[] }>).detail
      setErrors(detail?.errors ?? [])
      window.clearTimeout((on as unknown as { _t?: number })._t)
      ;(on as unknown as { _t?: number })._t = window.setTimeout(() => setErrors([]), 6000)
    }
    window.addEventListener('op:rejected', on)
    return () => window.removeEventListener('op:rejected', on)
  }, [])

  if (errors.length === 0) return null

  return (
    <div className="op-error-banner" role="alert" aria-live="assertive">
      <div className="op-error-banner__head">
        <span aria-hidden="true">⚠️</span>
        <span>{t('Saisie incomplète ou invalide')}</span>
        <button type="button" className="op-error-banner__close" aria-label={t('Fermer')} onClick={() => setErrors([])}>✕</button>
      </div>
      <ul className="op-error-banner__list">
        {errors.map((err, i) => (
          <li key={i}>{err}</li>
        ))}
      </ul>
    </div>
  )
}
