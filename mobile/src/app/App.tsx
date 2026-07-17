import { useEffect, useSyncExternalStore } from 'react'
import { BrowserRouter, Link, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import { getLocale, subscribeLocale, t } from '../i18n'
import { startSyncLoop } from '../offline/sync'
import { LoginScreen } from '../features/auth/LoginScreen'
import { HomeScreen } from '../features/home/HomeScreen'
import { NouvelleSaisieScreen } from '../features/saisie/NouvelleSaisieScreen'
import { DailyCheckScreen } from '../features/elevage/DailyCheckScreen'
import { EggCollectionScreen } from '../features/elevage/EggCollectionScreen'
import { IncidentScreen } from '../features/elevage/IncidentScreen'
import { BatchScreen } from '../features/elevage/BatchScreen'
import { ScanScreen } from '../features/scan/ScanScreen'
import { SaleScreen } from '../features/commerce/SaleScreen'
import { SalesJournalScreen } from '../features/commerce/SalesJournalScreen'
import { TreasuryJournalScreen } from '../features/tresorerie/TreasuryJournalScreen'
import { StockMovementScreen } from '../features/logistique/StockMovementScreen'
import { StocksScreen } from '../features/logistique/StocksScreen'
import { ExpenseScreen } from '../features/depenses/ExpenseScreen'
import { HarvestScreen } from '../features/cultures/HarvestScreen'
import { CropInputScreen } from '../features/cultures/CropInputScreen'
import { SlaughterScreen } from '../features/abattoir/SlaughterScreen'
import { SlaughterJournalScreen } from '../features/abattoir/SlaughterJournalScreen'
import { ReceptionScreen } from '../features/abattoir/ReceptionScreen'
import { TemperatureScreen } from '../features/abattoir/TemperatureScreen'
import { CcpScreen } from '../features/abattoir/CcpScreen'
import { CleaningScreen } from '../features/abattoir/CleaningScreen'
import { ByproductScreen } from '../features/abattoir/ByproductScreen'
import { MillCompleteScreen } from '../features/provenderie/MillCompleteScreen'
import { MillJournalScreen } from '../features/provenderie/MillJournalScreen'
import { MonEspaceScreen } from '../features/mon-espace/MonEspaceScreen'
import { TachesScreen } from '../features/taches/TachesScreen'
import { NotificationsScreen } from '../features/notifications/NotificationsScreen'
import { BottomNav } from '../ui/BottomNav'
import { SyncBadge } from '../ui/SyncBadge'

function Shell() {
  const { me, loading } = useAuth()
  // Changement de langue → re-rendu complet (t() lit un état module).
  const locale = useSyncExternalStore(subscribeLocale, getLocale)
  const onHome = useLocation().pathname === '/'
  const navigate = useNavigate()

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
            <img src="/biocrest-mark.svg" alt="" />
          </span>
          <span className="brand-name">Bio<b>crest</b></span>
        </div>
        <SyncBadge />
      </header>
      {/* Barre de retour — présente sur tout écran hors accueil (cible pouce). */}
      {!onHome && (
        <div className="subbar">
          <button type="button" className="subbar-back" onClick={() => navigate(-1)}>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
              <path d="m15 18-6-6 6-6" />
            </svg>
            {t('Retour')}
          </button>
        </div>
      )}
      <main className="app-main">
        <Routes>
          <Route path="/" element={<HomeScreen />} />
          <Route path="/nouvelle" element={<NouvelleSaisieScreen />} />
          <Route path="/elevage/pointage/:batchId?" element={<DailyCheckScreen />} />
          <Route path="/elevage/collecte/:batchId" element={<EggCollectionScreen />} />
          <Route path="/elevage/incident/:batchId" element={<IncidentScreen />} />
          <Route path="/lot/:batchId" element={<BatchScreen />} />
          <Route path="/scan" element={<ScanScreen />} />
          <Route path="/commerce/vente" element={<SaleScreen />} />
          <Route path="/commerce/journal" element={<SalesJournalScreen />} />
          <Route path="/tresorerie/journal" element={<TreasuryJournalScreen />} />
          <Route path="/provenderie/journal" element={<MillJournalScreen />} />
          <Route path="/abattoir/journal" element={<SlaughterJournalScreen />} />
          <Route path="/logistique/mouvement" element={<StockMovementScreen />} />
          <Route path="/logistique/stocks" element={<StocksScreen />} />
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
          <Route path="/taches" element={<TachesScreen />} />
          <Route path="/mon-espace" element={<MonEspaceScreen />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
      {/* FAB « + » — ajout rapide par scan universel (accueil seulement :
          sur un écran de saisie on ajoute déjà, il ferait doublon). */}
      {onHome && (
        <Link to="/nouvelle" className="fab" aria-label="Nouvelle saisie">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round">
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
      {/* BrowserRouter : URL propres (sans #). Le rewrite SPA vers index.html
          est assuré par mobile/public/.htaccess sur l'hébergement. */}
      <BrowserRouter>
        <Shell />
      </BrowserRouter>
    </AuthProvider>
  )
}
