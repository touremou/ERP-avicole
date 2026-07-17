/**
 * Client API v1 — fetch minimaliste, token Sanctum, ferme courante.
 *
 * Règles :
 * - Le token vit dans Dexie (meta), pas dans localStorage — une seule source.
 * - X-Farm-Id est envoyé si l'utilisateur a choisi une ferme (multi-sites).
 * - 401 → purge de session et retour au login (token révoqué/expiré).
 * - AUCUN cache HTTP : la vérité offline vit dans Dexie (miroir + outbox).
 */
import { db } from '../offline/db'
import type {
  DeviceInfo,
  LoginResponse,
  MeResponse,
  BatchHistoryResponse,
  HarvestJournalResponse,
  MillJournalResponse,
  NotificationsResponse,
  SalesJournalResponse,
  SlaughterJournalResponse,
  TreasuryJournalResponse,
  PhotoUploadResponse,
  PushOperation,
  PushResponse,
  PullResponse,
  TasksResponse,
} from './types'

// Base de l'API. En dev et en même-origine (PWA servie par le même hôte que
// Laravel, ou reverse-proxy /api → Laravel), on reste en relatif `/api/v1`.
// En déploiement cross-origine (PWA sur app.*, API sur le domaine principal),
// on fixe VITE_API_BASE_URL au build (ex. https://ferme.example.com/api/v1)
// — cf. docs/mobile/deploiement-staging.md.
const BASE = (import.meta.env.VITE_API_BASE_URL ?? '/api/v1').replace(/\/$/, '')

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors?: Record<string, string[]>,
  ) {
    super(message)
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = (await db.meta.get('token'))?.value as string | undefined
  const farmId = (await db.meta.get('farm_id'))?.value as number | undefined

  const response = await fetch(`${BASE}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(farmId ? { 'X-Farm-Id': String(farmId) } : {}),
      ...options.headers,
    },
  })

  if (response.status === 401) {
    // Token révoqué (téléphone déclaré perdu) ou expiré : session terminée.
    await clearSession()
    window.dispatchEvent(new CustomEvent('auth:expired'))
  }

  const body = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(response.status, body.message ?? `Erreur ${response.status}`, body.errors)
  }

  return body as T
}

export async function clearSession(): Promise<void> {
  await db.meta.bulkDelete(['token', 'me', 'farm_id'])
}

// ── Endpoints ────────────────────────────────────────────────────────────

export const api = {
  login: (email: string, password: string, deviceName: string) =>
    request<LoginResponse>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password, device_name: deviceName }),
    }),

  me: () => request<MeResponse>('/auth/me'),

  logout: () => request<{ message: string }>('/auth/logout', { method: 'POST' }),

  devices: () => request<{ devices: DeviceInfo[] }>('/devices'),

  revokeDevice: (id: number) =>
    request<{ message: string }>(`/devices/${id}`, { method: 'DELETE' }),

  syncPush: (operations: PushOperation[]) =>
    request<PushResponse>('/sync/push', {
      method: 'POST',
      body: JSON.stringify({ operations }),
    }),

  syncPull: (since: string | null) =>
    request<PullResponse>(`/sync/pull${since ? `?since=${encodeURIComponent(since)}` : ''}`),

  notifications: () => request<NotificationsResponse>('/notifications'),

  tasks: () => request<TasksResponse>('/tasks'),

  salesToday: (period = 'today') => request<SalesJournalResponse>(`/sales/today?period=${period}`),

  treasuryToday: (period = 'today') => request<TreasuryJournalResponse>(`/treasury/today?period=${period}`),

  provenderieToday: (period = 'today') => request<MillJournalResponse>(`/provenderie/today?period=${period}`),

  abattoirToday: (period = 'today') => request<SlaughterJournalResponse>(`/abattoir/today?period=${period}`),

  culturesToday: (period = 'today') => request<HarvestJournalResponse>(`/cultures/today?period=${period}`),

  batchHistory: (id: number) => request<BatchHistoryResponse>(`/batches/${id}/history`),

  markNotificationRead: (id: string) =>
    request<{ message: string }>(`/notifications/${id}/read`, { method: 'POST' }),

  markAllNotificationsRead: () =>
    request<{ message: string }>('/notifications/read-all', { method: 'POST' }),

  /** Multipart — on laisse le navigateur poser le boundary (pas de JSON ici). */
  uploadPhoto: async (blob: Blob, context: string): Promise<PhotoUploadResponse> => {
    const token = (await db.meta.get('token'))?.value as string | undefined
    const farmId = (await db.meta.get('farm_id'))?.value as number | undefined
    const form = new FormData()
    form.append('photo', blob, 'photo.jpg')
    form.append('context', context)

    const response = await fetch(`${BASE}/photos`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(farmId ? { 'X-Farm-Id': String(farmId) } : {}),
      },
      body: form,
    })
    const body = await response.json().catch(() => ({}))
    if (!response.ok) throw new ApiError(response.status, body.message ?? `Erreur ${response.status}`, body.errors)
    return body as PhotoUploadResponse
  },
}
