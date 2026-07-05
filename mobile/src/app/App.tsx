import { useEffect } from 'react'
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import { startSyncLoop } from '../offline/sync'
import { LoginScreen } from '../features/auth/LoginScreen'
import { HomeScreen } from '../features/home/HomeScreen'
import { DailyCheckScreen } from '../features/elevage/DailyCheckScreen'
import { MonEspaceScreen } from '../features/mon-espace/MonEspaceScreen'
import { BottomNav } from '../ui/BottomNav'
import { SyncBadge } from '../ui/SyncBadge'

function Shell() {
  const { me, loading } = useAuth()

  useEffect(() => {
    if (me) startSyncLoop()
  }, [me])

  if (loading) return <div className="screen-center">Chargement…</div>
  if (!me) return <LoginScreen />

  return (
    <div className="app-shell">
      <header className="app-header">
        <span className="app-title">AviTerrain</span>
        <SyncBadge />
      </header>
      <main className="app-main">
        <Routes>
          <Route path="/" element={<HomeScreen />} />
          <Route path="/elevage/pointage/:batchId?" element={<DailyCheckScreen />} />
          <Route path="/mon-espace" element={<MonEspaceScreen />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
      <BottomNav />
    </div>
  )
}

export function App() {
  return (
    <AuthProvider>
      {/* HashRouter : aucune config serveur requise pour un hébergement statique. */}
      <HashRouter>
        <Shell />
      </HashRouter>
    </AuthProvider>
  )
}
