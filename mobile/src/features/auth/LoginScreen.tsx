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
    <div className="screen-center">
      <form className="login-card" onSubmit={onSubmit}>
        <h1>🐔 AviTerrain</h1>
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
