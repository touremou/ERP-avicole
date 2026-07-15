/**
 * Scan QR — point d'entrée UNIVERSEL des saisies (anti-corvée) : on flashe
 * n'importe quelle étiquette de traçabilité de l'ERP et l'écran d'action
 * correspondant s'ouvre, prêt à saisir :
 *   étiquette lot (…/trace/lot/{code})   → fiche d'actions du lot
 *   étiquette OP  (…/trace/op/{numéro})  → clôture de l'OP provenderie
 *   code brut : lot, n° d'ordre d'abattage, code de cycle de culture, n° OP.
 * BarcodeDetector natif (Chrome/Android) ; repli : saisie manuelle du code —
 * aucun périphérique n'est bloqué.
 */
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { db } from '../../offline/db'
import { t } from '../../i18n'

/** Extrait le code lot d'un contenu de QR (URL /trace/lot/{code} ou code brut). */
export function parseBatchCode(raw: string): string {
  const match = /\/trace\/lot\/([^/?#]+)/.exec(raw)
  return decodeURIComponent(match ? match[1] : raw).trim().toUpperCase()
}

/**
 * Résout un contenu scanné vers la route d'action locale. Ordre d'essai :
 * URLs de traçabilité explicites, puis codes bruts contre chaque miroir
 * local (lot → ordre d'abattage → OP provenderie → cycle de culture).
 * Renvoie null si rien ne correspond hors-ligne.
 */
export async function resolveScan(raw: string): Promise<string | null> {
  const opUrl = /\/trace\/op\/([^/?#]+)/.exec(raw)
  if (opUrl) {
    const op = await db.ref_mill_productions
      .filter((p) => p.batch_number.toUpperCase() === decodeURIComponent(opUrl[1]).trim().toUpperCase())
      .first()
    return op ? `/provenderie/cloture/${op.id}` : null
  }

  const code = parseBatchCode(raw) // gère /trace/lot/{code} ET le code brut

  const batch = await db.ref_batches.where('code').equals(code).first()
  if (batch) return `/lot/${batch.id}`

  const order = await db.ref_slaughter_orders
    .filter((o) => o.order_number.toUpperCase() === code)
    .first()
  if (order) return `/abattoir/execution/${order.id}`

  const op = await db.ref_mill_productions
    .filter((p) => p.batch_number.toUpperCase() === code)
    .first()
  if (op) return `/provenderie/cloture/${op.id}`

  const cycle = await db.ref_crop_cycles.filter((c) => c.code.toUpperCase() === code).first()
  if (cycle) return `/cultures/recolte/${cycle.id}`

  return null
}

export function ScanScreen() {
  const navigate = useNavigate()
  const videoRef = useRef<HTMLVideoElement>(null)
  const [supported, setSupported] = useState(false)
  const [manualCode, setManualCode] = useState('')
  const [error, setError] = useState<string | null>(null)

  async function openScanned(raw: string) {
    const target = await resolveScan(raw)
    if (!target) {
      setError(t('« :code » introuvable en local (lot, ordre, OP ou cycle) — vérifiez ou synchronisez.', {
        code: parseBatchCode(raw),
      }))
      return
    }
    navigate(target)
  }

  useEffect(() => {
    const hasDetector = 'BarcodeDetector' in window
    setSupported(hasDetector)
    if (!hasDetector) return

    let stream: MediaStream | null = null
    let stopped = false

    void (async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' },
        })
        if (!videoRef.current) return
        videoRef.current.srcObject = stream
        await videoRef.current.play()

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const detector = new (window as any).BarcodeDetector({ formats: ['qr_code'] })
        const tick = async () => {
          if (stopped || !videoRef.current) return
          try {
            const codes = await detector.detect(videoRef.current)
            if (codes.length > 0) {
              await openScanned(codes[0].rawValue as string)
              return
            }
          } catch {
            // Frame illisible : on continue.
          }
          setTimeout(() => void tick(), 300)
        }
        void tick()
      } catch {
        setSupported(false) // caméra refusée → repli saisie manuelle
      }
    })()

    return () => {
      stopped = true
      stream?.getTracks().forEach((track) => track.stop())
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="screen">
      <h2>{t('📷 Scanner une étiquette')}</h2>

      {supported ? (
        <video ref={videoRef} className="scan-video" muted playsInline />
      ) : (
        <p className="muted">
          {t('Scanner indisponible sur cet appareil — saisissez le code (imprimé sous le QR).')}
        </p>
      )}

      <label htmlFor="manual_code">{t('Code (lot, ordre, OP, cycle)')}</label>
      <input
        id="manual_code"
        value={manualCode}
        onChange={(e) => setManualCode(e.target.value.toUpperCase())}
        placeholder={t('ex. P-001')}
        autoCapitalize="characters"
      />
      <button
        type="button"
        className="btn-primary"
        disabled={!manualCode.trim()}
        onClick={() => void openScanned(manualCode)}
      >
        {t('Ouvrir')}
      </button>

      {error && <p className="error">{error}</p>}
    </div>
  )
}
