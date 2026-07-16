/**
 * Badge de synchronisation — règle UX n°7 : l'utilisateur terrain doit voir
 * d'un coup d'œil si son travail est « au chaud », sinon il ressaisit ou
 * doute de l'outil. Toujours visible dans le header.
 */
import { useEffect, useState } from 'react'
import { t } from '../i18n'
import { getLastSyncError, onSyncChange, syncNow, type SyncState } from '../offline/sync'

const LABELS: Record<SyncState, string> = {
  idle: '✓ Synchronisé',
  syncing: '⟳ Synchronisation…',
  offline: '📡 Hors-ligne',
  error: '⚠️ Erreur réseau',
}

export function SyncBadge() {
  const [state, setState] = useState<SyncState>('idle')
  const [pending, setPending] = useState(0)

  useEffect(
    () =>
      onSyncChange((s, p) => {
        setState(s)
        setPending(p)
      }),
    [],
  )

  const label =
    pending > 0 && state !== 'syncing'
      ? t(':label · :count en attente', { label: t(LABELS[state]), count: pending })
      : t(LABELS[state])

  const onTap = () => {
    // En erreur, on révèle la raison exacte AVANT de retenter (diagnostic terrain).
    if (state === 'error') {
      const reason = getLastSyncError()
      if (reason) window.alert(reason)
    }
    void syncNow()
  }

  return (
    <button
      type="button"
      className={`sync-badge sync-${state}`}
      title={state === 'error' ? getLastSyncError() ?? undefined : undefined}
      onClick={onTap}
    >
      {label}
    </button>
  )
}
