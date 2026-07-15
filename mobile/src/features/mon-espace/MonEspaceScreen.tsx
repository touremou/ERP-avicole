/**
 * Mon espace — mon activité (saisies locales + statut de sync), le bac
 * « À corriger » (refus définitifs avec motif serveur), ma session et le
 * choix de langue (prioritaire sur la langue du profil web, cf. i18n).
 */
import { useEffect, useState } from 'react'
import { useAuth } from '../../app/AuthContext'
import { db, type MyRecord, type OutboxEntry } from '../../offline/db'
import { syncNow } from '../../offline/sync'
import { getLocale, setLocale, t, type Locale } from '../../i18n'

const LOCALES: { value: Locale; label: string }[] = [
  { value: 'fr', label: '🇫🇷 Français' },
  { value: 'en', label: '🇬🇧 English' },
]

export function MonEspaceScreen() {
  const { me, logout } = useAuth()
  const [records, setRecords] = useState<MyRecord[]>([])
  const [review, setReview] = useState<OutboxEntry[]>([])

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
    void refresh()
  }, [])

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
        <span className="avatar" aria-hidden="true">{initials}</span>
        <div>
          <div className="profile-name">{me?.user.name}</div>
          <div className="profile-role">{me?.role.label ?? me?.role.slug}</div>
        </div>
      </div>

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
