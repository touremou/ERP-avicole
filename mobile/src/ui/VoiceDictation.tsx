/**
 * Dictée vocale (anti-corvée) — un micro à côté des champs d'observation :
 * l'agent parle, le texte s'ajoute au champ. Web Speech API
 * (SpeechRecognition), langue alignée sur la langue de l'app.
 *
 * Limites assumées : Chrome/Android uniquement, et la reconnaissance
 * passe par le service du navigateur → nécessite le RÉSEAU. Le bouton
 * disparaît si l'API est absente et se désactive hors-ligne — la saisie
 * clavier reste toujours possible, rien n'est bloqué.
 */
import { useEffect, useRef, useState } from 'react'
import { getLocale, t } from '../i18n'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnySpeechRecognition = any

function speechRecognitionCtor(): AnySpeechRecognition | null {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const w = window as any
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null
}

interface Props {
  /** Reçoit le texte dicté (à concaténer au champ par l'appelant). */
  onText: (text: string) => void
}

export function VoiceDictation({ onText }: Props) {
  const [listening, setListening] = useState(false)
  const [online, setOnline] = useState(navigator.onLine)
  const recognitionRef = useRef<AnySpeechRecognition | null>(null)
  const supported = speechRecognitionCtor() !== null

  useEffect(() => {
    const up = () => setOnline(true)
    const down = () => setOnline(false)
    window.addEventListener('online', up)
    window.addEventListener('offline', down)
    return () => {
      window.removeEventListener('online', up)
      window.removeEventListener('offline', down)
      recognitionRef.current?.stop?.()
    }
  }, [])

  if (!supported) return null

  function toggle() {
    if (listening) {
      recognitionRef.current?.stop?.()
      setListening(false)
      return
    }

    const Ctor = speechRecognitionCtor()
    if (!Ctor) return

    const recognition = new Ctor()
    recognition.lang = getLocale() === 'en' ? 'en-US' : 'fr-FR'
    recognition.interimResults = false
    recognition.continuous = false

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    recognition.onresult = (event: any) => {
      const transcript = Array.from(event.results)
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        .map((result: any) => result[0]?.transcript ?? '')
        .join(' ')
        .trim()
      if (transcript) onText(transcript)
    }
    recognition.onend = () => setListening(false)
    recognition.onerror = () => setListening(false)

    recognitionRef.current = recognition
    recognition.start()
    setListening(true)
  }

  return (
    <button
      type="button"
      className={`chip ${listening ? 'chip-danger' : ''}`}
      disabled={!online}
      onClick={toggle}
      title={online ? t('Dicter au lieu de taper') : t('Dictée indisponible hors-ligne')}
    >
      {listening ? t('🎤 En écoute… (toucher pour arrêter)') : t('🎤 Dicter')}
    </button>
  )
}
