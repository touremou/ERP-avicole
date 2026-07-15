import { useState, type FormEvent } from 'react'
import { useAuth } from '../../app/AuthContext'
import { ApiError } from '../../api/client'
import { t } from '../../i18n'

/** Nom d'appareil lisible côté admin (liste /devices, révocation à distance). */
function defaultDeviceName(): string {
  const ua = navigator.userAgent
  const model = /Android.+?;\s*([^);]+)/.exec(ua)?.[1] ?? (/iPhone|iPad/.test(ua) ? 'iPhone' : 'Mobile')
  return `${model.trim()}`.slice(0, 90)
}

export function LoginScreen() {
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setBusy(true)
    try {
      await login(email.trim(), password, defaultDeviceName())
    } catch (e) {
      setError(
        e instanceof ApiError
          ? e.message
          : t('Connexion impossible — vérifiez le réseau et réessayez.'),
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="screen-center login-bg">
      <form className="login-card" onSubmit={onSubmit}>
        <div className="login-brand">
          <span className="login-logo" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" />
              <path d="M2 21c0-3 1.85-5.36 5.08-6" />
            </svg>
          </span>
          <div>
            <div className="login-title">Bio<b>crest</b></div>
            <div className="login-tag">AviTerrain</div>
          </div>
        </div>
        <p className="muted">{t('Votre outil de terrain — fonctionne sans réseau une fois connecté.')}</p>

        <label htmlFor="email">{t('E-mail')}</label>
        <input
          id="email"
          type="email"
          inputMode="email"
          autoComplete="username"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />

        <label htmlFor="password">{t('Mot de passe')}</label>
        <input
          id="password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />

        {error && <p className="error">{error}</p>}

        <button type="submit" className="btn-primary" disabled={busy}>
          {busy ? t('Connexion…') : t('Se connecter')}
        </button>
      </form>
    </div>
  )
}
