import { useEffect } from 'react'
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import { startSyncLoop } from '../offline/sync'
import { LoginScreen } from '../features/auth/LoginScreen'
import { HomeScreen } from '../features/home/HomeScreen'
import { DailyCheckScreen } from '../features/elevage/DailyCheckScreen'
import { EggCollectionScreen } from '../features/elevage/EggCollectionScreen'
import { IncidentScreen } from '../features/elevage/IncidentScreen'
import { BatchScreen } from '../features/elevage/BatchScreen'
import { ScanScreen } from '../features/scan/ScanScreen'
import { SaleScreen } from '../features/commerce/SaleScreen'
import { StockMovementScreen } from '../features/logistique/StockMovementScreen'
import { ExpenseScreen } from '../features/depenses/ExpenseScreen'
import { HarvestScreen } from '../features/cultures/HarvestScreen'
import { CropInputScreen } from '../features/cultures/CropInputScreen'
import { SlaughterScreen } from '../features/abattoir/SlaughterScreen'
import { MillCompleteScreen } from '../features/provenderie/MillCompleteScreen'
import { MonEspaceScreen } from '../features/mon-espace/MonEspaceScreen'
import { NotificationsScreen } from '../features/notifications/NotificationsScreen'
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
          <Route path="/elevage/collecte/:batchId" element={<EggCollectionScreen />} />
          <Route path="/elevage/incident/:batchId" element={<IncidentScreen />} />
          <Route path="/lot/:batchId" element={<BatchScreen />} />
          <Route path="/scan" element={<ScanScreen />} />
          <Route path="/commerce/vente" element={<SaleScreen />} />
          <Route path="/logistique/mouvement" element={<StockMovementScreen />} />
          <Route path="/depenses/nouvelle" element={<ExpenseScreen />} />
          <Route path="/cultures/recolte/:cycleId" element={<HarvestScreen />} />
          <Route path="/cultures/intrant/:cycleId" element={<CropInputScreen />} />
          <Route path="/abattoir/execution/:orderId" element={<SlaughterScreen />} />
          <Route path="/provenderie/cloture/:opId" element={<MillCompleteScreen />} />
          <Route path="/alertes" element={<NotificationsScreen />} />
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
