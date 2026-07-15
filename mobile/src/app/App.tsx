import { useEffect, useSyncExternalStore } from 'react'
import { HashRouter, Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import { getLocale, subscribeLocale } from '../i18n'
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
import { ReceptionScreen } from '../features/abattoir/ReceptionScreen'
import { TemperatureScreen } from '../features/abattoir/TemperatureScreen'
import { CcpScreen } from '../features/abattoir/CcpScreen'
import { CleaningScreen } from '../features/abattoir/CleaningScreen'
import { ByproductScreen } from '../features/abattoir/ByproductScreen'
import { MillCompleteScreen } from '../features/provenderie/MillCompleteScreen'
import { MonEspaceScreen } from '../features/mon-espace/MonEspaceScreen'
import { NotificationsScreen } from '../features/notifications/NotificationsScreen'
import { BottomNav } from '../ui/BottomNav'
import { SyncBadge } from '../ui/SyncBadge'

function Shell() {
  const { me, loading } = useAuth()
  // Changement de langue → re-rendu complet (t() lit un état module).
  const locale = useSyncExternalStore(subscribeLocale, getLocale)
  const onHome = useLocation().pathname === '/'

  useEffect(() => {
    if (me) startSyncLoop()
  }, [me])

  if (loading) return <div className="screen-center">Chargement…</div>
  if (!me) return <LoginScreen />

  return (
    <div className="app-shell" key={locale}>
      <header className="app-header">
        <div className="brand">
          <span className="brand-mark" aria-hidden="true">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" />
              <path d="M2 21c0-3 1.85-5.36 5.08-6" />
            </svg>
          </span>
          <span className="brand-name">Bio<b>crest</b></span>
        </div>
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
          <Route path="/abattoir/reception" element={<ReceptionScreen />} />
          <Route path="/abattoir/temperature" element={<TemperatureScreen />} />
          <Route path="/abattoir/ccp" element={<CcpScreen />} />
          <Route path="/abattoir/nettoyage" element={<CleaningScreen />} />
          <Route path="/abattoir/sousproduit" element={<ByproductScreen />} />
          <Route path="/provenderie/cloture/:opId" element={<MillCompleteScreen />} />
          <Route path="/alertes" element={<NotificationsScreen />} />
          <Route path="/mon-espace" element={<MonEspaceScreen />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
      {/* FAB « + » — ajout rapide par scan universel (accueil seulement :
          sur un écran de saisie on ajoute déjà, il ferait doublon). */}
      {onHome && (
        <Link to="/scan" className="fab" aria-label="Ajouter une saisie (scanner)">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2.6" strokeLinecap="round">
            <path d="M12 5v14M5 12h14" />
          </svg>
        </Link>
      )}
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
