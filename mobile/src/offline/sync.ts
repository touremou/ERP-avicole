/**
 * Moteur de synchronisation — le cycle décrit dans la RFC §3.2 :
 *
 *   saisie → my_records (affichage) + outbox (pending)
 *   retour réseau (ou bouton) :
 *     1. PUSH : vider l'outbox par lot → statut PAR opération
 *     2. PULL : /sync/pull?since=last_pull_at → upserts + tombstones
 *     3. last_pull_at = server_time (le SERVEUR fait foi, jamais l'horloge locale)
 *
 * Traitement des statuts (phase-0-spec §4.2) :
 *   success / already_synced → retirer de l'outbox, marquer my_records synced
 *   conflict / validation_failed → sortir de la file → bac « À corriger »
 *   permission_denied → idem review (les droits ont changé depuis le cache)
 *   error (5xx serveur) → RESTE pending, retenté au prochain cycle (backoff)
 */
import { api } from '../api/client'
import { db, getMeta, setMeta, type OutboxEntry } from './db'
import type { OperationType, PullResponse } from '../api/types'

export type SyncState = 'idle' | 'syncing' | 'offline' | 'error'

type Listener = (state: SyncState, pendingCount: number) => void
const listeners = new Set<Listener>()
let currentState: SyncState = 'idle'
let running = false

export function onSyncChange(listener: Listener): () => void {
  listeners.add(listener)
  void notify()
  return () => listeners.delete(listener)
}

async function notify(state?: SyncState): Promise<void> {
  if (state) currentState = state
  const pending = await db.outbox.where('status').equals('pending').count()
  listeners.forEach((l) => l(currentState, pending))
}

/** Enregistre une saisie : affichage immédiat + file d'attente. Offline-safe. */
export async function enqueue(
  type: OperationType,
  payload: Record<string, unknown>,
  label: string,
): Promise<string> {
  const opUuid = crypto.randomUUID()
  // Le payload porte son propre uuid métier = op_uuid (idempotence serveur).
  const fullPayload = { uuid: opUuid, ...payload }
  const now = new Date().toISOString()

  await db.transaction('rw', db.outbox, db.my_records, async () => {
    await db.outbox.add({
      op_uuid: opUuid,
      type,
      payload: fullPayload,
      status: 'pending',
      attempts: 0,
      created_at: now,
      last_error: null,
      server_errors: null,
    })
    await db.my_records.add({
      uuid: opUuid,
      type,
      label,
      payload: fullPayload,
      sync_status: 'pending',
      created_at: now,
    })
  })

  await notify()
  // Tentative opportuniste — silencieuse si hors-ligne.
  void syncNow()
  return opUuid
}

/** Cycle complet push → pull. Réentrant-safe (une seule exécution à la fois). */
export async function syncNow(): Promise<void> {
  if (running) return
  if (!navigator.onLine) {
    await notify('offline')
    return
  }

  running = true
  await notify('syncing')

  try {
    await pushOutbox()
    await pullDelta()
    await refreshNotifications()
    await notify('idle')
  } catch {
    // Réseau tombé en plein cycle : l'outbox est intacte, on retentera.
    await notify(navigator.onLine ? 'error' : 'offline')
  } finally {
    running = false
  }
}

async function pushOutbox(): Promise<void> {
  const pending = await db.outbox.where('status').equals('pending').sortBy('created_at')
  if (pending.length === 0) return

  // Photos d'abord : une op qui référence une photo locale (payload.photo_uuid)
  // ne part qu'une fois la photo téléversée et son chemin serveur substitué.
  // Rejouable : le chemin est persisté sur la photo ET dans le payload — un
  // échec de push ultérieur ne re-téléverse pas.
  for (const entry of pending) {
    const photoUuid = entry.payload.photo_uuid as string | undefined
    if (!photoUuid) continue

    const photo = await db.photos.get(photoUuid)
    if (!photo) {
      // Photo disparue (purge navigateur) : l'op part sans photo plutôt
      // que de bloquer la file.
      delete entry.payload.photo_uuid
      await db.outbox.update(entry.op_uuid, { payload: entry.payload })
      continue
    }

    const path = photo.uploaded_path ?? (await api.uploadPhoto(photo.blob, photo.context)).path
    await db.photos.update(photoUuid, { uploaded_path: path })

    entry.payload.photo_path = path
    delete entry.payload.photo_uuid
    await db.outbox.update(entry.op_uuid, { payload: entry.payload })
  }

  // Lots de 50 (le serveur accepte max 100) pour borner la taille de requête.
  for (let i = 0; i < pending.length; i += 50) {
    const batch = pending.slice(i, i + 50)
    const response = await api.syncPush(
      batch.map(({ op_uuid, type, payload }) => ({ op_uuid, type, payload })),
    )

    for (const result of response.results) {
      const entry = batch.find((e) => e.op_uuid === result.op_uuid)
      if (!entry) continue

      switch (result.status) {
        case 'success':
        case 'already_synced': {
          await db.outbox.delete(result.op_uuid)
          await db.my_records.update(result.op_uuid, { sync_status: 'synced' })
          // La photo est au serveur : on libère l'espace local.
          const path = entry.payload.photo_path as string | undefined
          if (path) await db.photos.where('uploaded_path').equals(path).delete()
          break
        }

        case 'conflict':
        case 'validation_failed':
        case 'permission_denied':
          // Refus définitif : sort de la file, visible dans « À corriger »
          // avec le motif serveur — l'utilisateur arbitre, la file ne bloque pas.
          await db.outbox.update(result.op_uuid, {
            status: 'review',
            last_error: result.message ?? result.status,
            server_errors: result.errors ?? null,
          } satisfies Partial<OutboxEntry>)
          await db.my_records.update(result.op_uuid, { sync_status: 'review' })
          break

        default:
          // 'error' (5xx interne) : reste pending, sera retenté.
          await db.outbox.update(result.op_uuid, {
            attempts: entry.attempts + 1,
            last_error: result.message ?? 'Erreur serveur',
          } satisfies Partial<OutboxEntry>)
      }
    }
  }
}

async function pullDelta(): Promise<void> {
  const since = (await getMeta<string>('last_pull_at')) ?? null
  const response: PullResponse = await api.syncPull(since)

  const { batches, buildings, stocks, clients, products, production_types } = response.entities

  await db.transaction(
    'rw',
    [db.ref_batches, db.ref_buildings, db.ref_stocks, db.ref_clients, db.ref_products, db.ref_production_types],
    async () => {
      await db.ref_batches.bulkPut(batches.upserts)
      await db.ref_batches.bulkDelete(batches.deletes)
      await db.ref_buildings.bulkPut(buildings.upserts)
      await db.ref_buildings.bulkDelete(buildings.deletes)
      await db.ref_stocks.bulkPut(stocks.upserts)
      await db.ref_stocks.bulkDelete(stocks.deletes)
      await db.ref_clients.bulkPut(clients.upserts)
      await db.ref_clients.bulkDelete(clients.deletes)
      await db.ref_products.bulkPut(products.upserts)
      await db.ref_products.bulkDelete(products.deletes)
      if (production_types) {
        await db.ref_production_types.bulkPut(production_types.upserts)
        await db.ref_production_types.bulkDelete(production_types.deletes)
      }
    },
  )

  await setMeta('last_pull_at', response.server_time)
}

/** Miroir local des notifications (lecture hors-ligne du centre d'alertes). */
async function refreshNotifications(): Promise<void> {
  const response = await api.notifications()
  await db.transaction('rw', db.notifications, async () => {
    // Remplacement complet : le serveur renvoie les 50 dernières, qui sont
    // la fenêtre utile terrain — pas de pagination locale à gérer.
    await db.notifications.clear()
    await db.notifications.bulkPut(response.notifications)
  })
  window.dispatchEvent(new CustomEvent('notifications:updated'))
}

/** À appeler une fois au démarrage de l'app. */
export function startSyncLoop(): void {
  window.addEventListener('online', () => void syncNow())
  window.addEventListener('offline', () => void notify('offline'))
  // Cycle périodique de filet (10 min) — le gros du travail se fait au
  // retour réseau et après chaque saisie.
  setInterval(() => void syncNow(), 10 * 60 * 1000)
  void syncNow()
}
