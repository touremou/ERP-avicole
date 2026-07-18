/**
 * Mon espace — mon profil (édition : coordonnées, langue serveur, mot de
 * passe), mon activité (saisies locales + statut de sync), le bac « À
 * corriger » (refus définitifs avec motif serveur), ma session et le choix
 * de langue local (prioritaire sur la langue du profil web, cf. i18n).
 */
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../app/AuthContext'
import { api, ApiError } from '../../api/client'
import { compressImage } from '../../platform'
import { db, type MyRecord, type OutboxEntry } from '../../offline/db'
import { safeLoad } from '../../offline/safeLoad'
import { syncNow, switchFarm } from '../../offline/sync'
import { getLocale, setLocale, t, type Locale } from '../../i18n'

const LOCALES: { value: Locale; label: string }[] = [
  { value: 'fr', label: '🇫🇷 Français' },
  { value: 'en', label: '🇬🇧 English' },
]

/** Message d'erreur lisible : 1re erreur de champ, sinon message générique. */
function errorText(error: unknown): string {
  if (error instanceof ApiError) {
    const first = error.errors && Object.values(error.errors)[0]?.[0]
    return first ?? error.message
  }
  return t('Une erreur est survenue.')
}

export function MonEspaceScreen() {
  const { me, logout, refreshMe } = useAuth()
  const [records, setRecords] = useState<MyRecord[]>([])
  const [review, setReview] = useState<OutboxEntry[]>([])
  const [online, setOnline] = useState(navigator.onLine)

  // Sélecteur de ferme (multi-sites).
  const [switchingFarm, setSwitchingFarm] = useState<number | null>(null)

  // Photo de profil.
  const fileInput = useRef<HTMLInputElement>(null)
  const [avatarBusy, setAvatarBusy] = useState(false)
  const [avatarMsg, setAvatarMsg] = useState<string | null>(null)

  // Édition du profil.
  const [editProfile, setEditProfile] = useState(false)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [locale, setProfileLocale] = useState<string>('fr')
  const [savingProfile, setSavingProfile] = useState(false)
  const [profileMsg, setProfileMsg] = useState<{ ok: boolean; text: string } | null>(null)

  // Changement de mot de passe.
  const [editPassword, setEditPassword] = useState(false)
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [savingPassword, setSavingPassword] = useState(false)
  const [passwordMsg, setPasswordMsg] = useState<{ ok: boolean; text: string } | null>(null)

  const statusLabel: Record<MyRecord['sync_status'], string> = {
    pending: t('⏳ En attente'),
    synced: t('✓ Synchronisé'),
    review: t('⚠️ À corriger'),
  }
  const statusClass: Record<MyRecord['sync_status'], string> = {
    pending: 'act-warn',
    synced: 'act-ok',
    review: 'act-crit',
  }

  const initials = (me?.user.name ?? '?')
    .split(' ')
    .map((w) => w[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()

  async function refresh() {
    setRecords(await db.my_records.orderBy('created_at').reverse().limit(30).toArray())
    setReview(await db.outbox.where('status').equals('review').toArray())
  }

  useEffect(() => {
    void safeLoad('mon-espace', refresh)
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => {
      window.removeEventListener('online', on)
      window.removeEventListener('offline', off)
    }
  }, [])

  // (Ré)initialise le formulaire de profil à partir de la session en cache.
  function openProfileForm() {
    setName(me?.user.name ?? '')
    setEmail(me?.user.email ?? '')
    setPhone(me?.user.phone ?? '')
    setProfileLocale(me?.user.locale ?? getLocale())
    setProfileMsg(null)
    setEditProfile(true)
  }

  async function submitProfile(event: React.FormEvent) {
    event.preventDefault()
    if (savingProfile) return
    setSavingProfile(true)
    setProfileMsg(null)
    try {
      await api.updateProfile({ name: name.trim(), email: email.trim(), phone: phone.trim() || null, locale })
      await refreshMe()
      setProfileMsg({ ok: true, text: t('Profil mis à jour.') })
      setEditProfile(false)
    } catch (error) {
      setProfileMsg({ ok: false, text: errorText(error) })
    } finally {
      setSavingProfile(false)
    }
  }

  async function submitPassword(event: React.FormEvent) {
    event.preventDefault()
    if (savingPassword) return
    setSavingPassword(true)
    setPasswordMsg(null)
    try {
      await api.changePassword({
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      })
      setPasswordMsg({ ok: true, text: t('Mot de passe mis à jour.') })
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      setEditPassword(false)
    } catch (error) {
      setPasswordMsg({ ok: false, text: errorText(error) })
    } finally {
      setSavingPassword(false)
    }
  }

  async function onPickAvatar(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    event.target.value = '' // permet de re-choisir le même fichier
    if (!file || avatarBusy) return
    setAvatarBusy(true)
    setAvatarMsg(null)
    try {
      const blob = await compressImage(file, 512, 0.85)
      await api.updateAvatar(blob)
      await refreshMe()
    } catch (error) {
      setAvatarMsg(error instanceof ApiError ? error.message : t('Une erreur est survenue.'))
    } finally {
      setAvatarBusy(false)
    }
  }

  async function removeAvatar() {
    if (avatarBusy) return
    setAvatarBusy(true)
    setAvatarMsg(null)
    try {
      await api.deleteAvatar()
      await refreshMe()
    } catch (error) {
      setAvatarMsg(error instanceof ApiError ? error.message : t('Une erreur est survenue.'))
    } finally {
      setAvatarBusy(false)
    }
  }

  async function onSwitchFarm(farmId: number) {
    if (switchingFarm || farmId === me?.scope.farm_id) return
    setSwitchingFarm(farmId)
    try {
      await switchFarm(farmId)   // purge + re-synchro complète pour le nouveau site
      await refreshMe()          // met à jour scope.farm_id + permissions du site
    } finally {
      setSwitchingFarm(null)
    }
  }

  async function discard(opUuid: string) {
    // Abandon d'une opération refusée : on la retire de la file ET de
    // l'activité locale (elle n'a jamais existé côté serveur).
    await db.outbox.delete(opUuid)
    await db.my_records.delete(opUuid)
    await refresh()
  }

  return (
    <div className="screen">
      <div className="profile-card">
        {me?.user.avatar_url ? (
          <img className="avatar avatar-img" src={me.user.avatar_url} alt={me?.user.name ?? ''} />
        ) : (
          <span className="avatar" aria-hidden="true">{initials}</span>
        )}
        <div className="profile-card__info">
          <div className="profile-name">{me?.user.name}</div>
          <div className="profile-role">{me?.role.label ?? me?.role.slug}</div>
          {online && (
            <div className="avatar-actions">
              <input ref={fileInput} type="file" accept="image/*" hidden onChange={onPickAvatar} />
              <button type="button" className="link-btn" disabled={avatarBusy} onClick={() => fileInput.current?.click()}>
                {avatarBusy ? t('Envoi…') : me?.user.avatar_url ? t('Changer la photo') : t('Ajouter une photo')}
              </button>
              {me?.user.avatar_url && (
                <button type="button" className="link-btn link-btn--danger" disabled={avatarBusy} onClick={() => void removeAvatar()}>
                  {t('Retirer')}
                </button>
              )}
            </div>
          )}
          {avatarMsg && <p className="error">{avatarMsg}</p>}
        </div>
      </div>

      <section>
        <div className="section-head"><h3>{t('Mon profil')}</h3></div>
        {!editProfile ? (
          <>
            <div className="record-row"><span className="muted">{t('E-mail')}</span><span>{me?.user.email}</span></div>
            <div className="record-row"><span className="muted">{t('Téléphone')}</span><span>{me?.user.phone || '—'}</span></div>
            {profileMsg?.ok && <p className="act-ok">{profileMsg.text}</p>}
            <button type="button" className="btn-secondary" onClick={openProfileForm} disabled={!online}>
              {t('Modifier mon profil')}
            </button>
            {!online && <p className="muted">{t('Connexion requise pour modifier le profil.')}</p>}
          </>
        ) : (
          <form onSubmit={submitProfile}>
            <label htmlFor="pf-name">{t('Nom')}</label>
            <input id="pf-name" type="text" value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} />

            <label htmlFor="pf-email">{t('E-mail')}</label>
            <input id="pf-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />

            <label htmlFor="pf-phone">{t('Téléphone')}</label>
            <input id="pf-phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} maxLength={30} placeholder="620000000" />

            <label htmlFor="pf-locale">{t('Langue par défaut (profil)')}</label>
            <select id="pf-locale" value={locale} onChange={(e) => setProfileLocale(e.target.value)}>
              <option value="fr">Français</option>
              <option value="en">English</option>
            </select>

            {profileMsg && !profileMsg.ok && <p className="error">{profileMsg.text}</p>}
            <button type="submit" className="btn-primary" disabled={savingProfile || !name.trim() || !email.trim()}>
              {savingProfile ? t('Enregistrement…') : t('Enregistrer')}
            </button>
            <button type="button" className="btn-secondary" onClick={() => setEditProfile(false)}>{t('Annuler')}</button>
          </form>
        )}
      </section>

      <section>
        <div className="section-head"><h3>{t('Sécurité')}</h3></div>
        {!editPassword ? (
          <>
            {passwordMsg?.ok && <p className="act-ok">{passwordMsg.text}</p>}
            <button type="button" className="btn-secondary" onClick={() => { setPasswordMsg(null); setEditPassword(true) }} disabled={!online}>
              {t('Changer le mot de passe')}
            </button>
            {!online && <p className="muted">{t('Connexion requise pour changer le mot de passe.')}</p>}
          </>
        ) : (
          <form onSubmit={submitPassword}>
            <label htmlFor="pw-current">{t('Mot de passe actuel')}</label>
            <input id="pw-current" type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required autoComplete="current-password" />

            <label htmlFor="pw-new">{t('Nouveau mot de passe')}</label>
            <input id="pw-new" type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} required autoComplete="new-password" />
            <span className="muted">{t('Au moins 8 caractères, avec lettres et chiffres.')}</span>

            <label htmlFor="pw-confirm">{t('Confirmer le mot de passe')}</label>
            <input id="pw-confirm" type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} required autoComplete="new-password" />

            {passwordMsg && !passwordMsg.ok && <p className="error">{passwordMsg.text}</p>}
            <button type="submit" className="btn-primary" disabled={savingPassword || !currentPassword || !newPassword || !confirmPassword}>
              {savingPassword ? t('Enregistrement…') : t('Mettre à jour le mot de passe')}
            </button>
            <button type="button" className="btn-secondary" onClick={() => setEditPassword(false)}>{t('Annuler')}</button>
          </form>
        )}
      </section>

      {review.length > 0 && (
        <section>
          <h3>{t('À corriger')} ({review.length})</h3>
          {review.map((entry) => (
            <div key={entry.op_uuid} className="review-card">
              <p className="error">{entry.last_error}</p>
              {entry.server_errors && (
                <ul>
                  {Object.entries(entry.server_errors).map(([field, messages]) => (
                    <li key={field}>{messages.join(' ')}</li>
                  ))}
                </ul>
              )}
              <button type="button" className="btn-secondary" onClick={() => void discard(entry.op_uuid)}>
                {t('Abandonner cette saisie')}
              </button>
            </div>
          ))}
        </section>
      )}

      <section>
        <h3>{t('Mon activité')}</h3>
        {records.length === 0 && <p className="muted">{t('Aucune saisie sur cet appareil.')}</p>}
        {records.map((record) => (
          <div key={record.uuid} className="record-row">
            <span>{record.label}</span>
            <span className={`act-status ${statusClass[record.sync_status]}`}>{statusLabel[record.sync_status]}</span>
          </div>
        ))}
      </section>

      {me && me.scope.farms.length > 1 && (
        <section>
          <div className="section-head"><h3>{t('Ferme / site')}</h3></div>
          <p className="muted">{t('Les données terrain (lots, stocks, tâches…) sont celles du site sélectionné.')}</p>
          <div className="chip-row">
            {me.scope.farms.map((farm) => (
              <button
                key={farm.id}
                type="button"
                className={`chip ${farm.id === me.scope.farm_id ? 'chip-on' : ''}`}
                disabled={switchingFarm !== null}
                onClick={() => void onSwitchFarm(farm.id)}
              >
                🏡 {farm.name}{switchingFarm === farm.id ? ' · ' + t('Synchronisation…') : ''}
              </button>
            ))}
          </div>
        </section>
      )}

      <section>
        <div className="section-head"><h3>{t('Appareils connectés')}</h3></div>
        <Link to="/appareils" className="btn-secondary btn-link-row">
          📱 {t('Gérer mes appareils')}
        </Link>
      </section>

      <section>
        <h3>{t('Langue')}</h3>
        <div className="chip-row">
          {LOCALES.map((option) => (
            <button
              key={option.value}
              type="button"
              className={`chip ${getLocale() === option.value ? 'chip-on' : ''}`}
              onClick={() => void setLocale(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </section>

      <section>
        <button type="button" className="btn-secondary" onClick={() => void syncNow().then(refresh)}>
          {t('🔄 Synchroniser maintenant')}
        </button>
        <button type="button" className="btn-danger" onClick={() => void logout()}>
          {t('Se déconnecter')}
        </button>
      </section>
    </div>
  )
}
