import React from 'react'
import ReactDOM from 'react-dom/client'
import { initSwUpdate } from './swUpdate'
import { App } from './App'
import { initLocale } from '../i18n'
// Inter auto-hébergée (bundlée par Vite) : rendu identique hors-ligne, sans CDN.
import '@fontsource/inter/400.css'
import '@fontsource/inter/500.css'
import '@fontsource/inter/600.css'
import '@fontsource/inter/700.css'
import '@fontsource/inter/800.css'
import '../ui/styles.css'

// Service worker en mode « prompt » : un toast « Recharger » signale la
// nouvelle version (cf. UpdateToast). La version s'active de toute façon au
// prochain lancement complet — le terrain reste à jour sans store.
initSwUpdate()

// La langue persistée doit être restaurée AVANT le premier rendu (sinon un
// flash français précède l'anglais). Offline-safe : simple lecture Dexie.
void initLocale().then(() => {
  ReactDOM.createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
      <App />
    </React.StrictMode>,
  )
})
