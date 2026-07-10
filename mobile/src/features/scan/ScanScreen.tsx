/**
 * Scan QR de traçabilité — on flashe l'étiquette d'un lot (QR encodant
 * …/trace/lot/{code}, cf. QrCodeService) et sa fiche d'actions s'ouvre.
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

export function ScanScreen() {
  const navigate = useNavigate()
  const videoRef = useRef<HTMLVideoElement>(null)
  const [supported, setSupported] = useState(false)
  const [manualCode, setManualCode] = useState('')
  const [error, setError] = useState<string | null>(null)

  async function openBatch(code: string) {
    const batch = await db.ref_batches.where('code').equals(code).first()
    if (!batch) {
      setError(t('Lot « :code » introuvable en local — vérifiez le code ou synchronisez.', { code }))
      return
    }
    navigate(`/lot/${batch.id}`)
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
              await openBatch(parseBatchCode(codes[0].rawValue as string))
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
      <h2>{t('📷 Scanner un lot')}</h2>

      {supported ? (
        <video ref={videoRef} className="scan-video" muted playsInline />
      ) : (
        <p className="muted">
          {t('Scanner indisponible sur cet appareil — saisissez le code du lot (imprimé sous le QR).')}
        </p>
      )}

      <label htmlFor="manual_code">{t('Code du lot')}</label>
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
        onClick={() => void openBatch(parseBatchCode(manualCode))}
      >
        {t('Ouvrir le lot')}
      </button>

      {error && <p className="error">{error}</p>}
    </div>
  )
}
