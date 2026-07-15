/**
 * Adaptateurs plateforme — le point de bascule web ↔ Capacitor (RFC §2.2).
 *
 * Implémentation WEB en Phase 0 ; les signatures sont STABLES : quand la
 * rampe Capacitor s'active, seuls ces fichiers changent (Camera → plugin
 * natif, notifications → push FCM/APNs, secureStorage → Keystore/Keychain).
 * Aucun écran ne doit importer une API navigateur directement pour ces
 * capacités — toujours passer par ici.
 */

export const platform = {
  /** Photo (incident, reçu…) — web : input capture ; Capacitor : Camera plugin. */
  async takePhoto(): Promise<File | null> {
    return new Promise((resolve) => {
      const input = document.createElement('input')
      input.type = 'file'
      input.accept = 'image/*'
      input.capture = 'environment'
      input.onchange = () => resolve(input.files?.[0] ?? null)
      input.oncancel = () => resolve(null)
      input.click()
    })
  },

  /** Position (opération terrain) — web : Geolocation API ; Capacitor : plugin. */
  async getPosition(): Promise<{ lat: number; lng: number } | null> {
    if (!('geolocation' in navigator)) return null
    return new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
        () => resolve(null),
        { timeout: 8000, maximumAge: 60000 },
      )
    })
  },
}

/**
 * Compression d'image côté client (canvas → JPEG) : réseau faible et quota
 * IndexedDB obligent — on ne stocke ni n'envoie jamais l'original (5-12 Mo).
 */
export async function compressImage(file: File, maxSize = 1280, quality = 0.8): Promise<Blob> {
  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, maxSize / Math.max(bitmap.width, bitmap.height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('Compression impossible'))),
      'image/jpeg',
      quality,
    )
  })
}
