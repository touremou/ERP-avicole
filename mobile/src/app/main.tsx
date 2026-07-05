import React from 'react'
import ReactDOM from 'react-dom/client'
import { registerSW } from 'virtual:pwa-register'
import { App } from './App'
import '../ui/styles.css'

// Service worker : mise à jour silencieuse (autoUpdate) — le terrain a
// toujours la dernière version au prochain lancement, sans store.
registerSW({ immediate: true })

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
