import { useEffect, useSyncExternalStore, type ReactElement } from 'react'
import { BrowserRouter, Link, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthContext'
import { getLocale, subscribeLocale, t } from '../i18n'
import { startSyncLoop } from '../offline/sync'
import { allows, ROUTE_ACCESS, type RoutePath } from '../offline/access'
import { LoginScreen } from '../features/auth/LoginScreen'
import { HomeScreen } from '../features/home/HomeScreen'
import { NouvelleSaisieScreen } from '../features/saisie/NouvelleSaisieScreen'
import { DailyCheckScreen } from '../features/elevage/DailyCheckScreen'
import { EggCollectionScreen } from '../features/elevage/EggCollectionScreen'
import { IncidentScreen } from '../features/elevage/IncidentScreen'
import { HealthCareScreen } from '../features/elevage/HealthCareScreen'
import { HatcheryScreen } from '../features/elevage/HatcheryScreen'
import { MilkingScreen } from '../features/elevage/MilkingScreen'
import { BatchScreen } from '../features/elevage/BatchScreen'
import { ScanScreen } from '../features/scan/ScanScreen'
import { SaleScreen } from '../features/commerce/SaleScreen'
import { SalesJournalScreen } from '../features/commerce/SalesJournalScreen'
import { PaymentScreen } from '../features/commerce/PaymentScreen'
import { SaleReturnScreen } from '../features/commerce/SaleReturnScreen'
import { TreasuryJournalScreen } from '../features/tresorerie/TreasuryJournalScreen'
import { StockMovementScreen } from '../features/logistique/StockMovementScreen'
import { StocksScreen } from '../features/logistique/StocksScreen'
import { InventoryCountScreen } from '../features/logistique/InventoryCountScreen'
import { FeedReceptionScreen } from '../features/logistique/FeedReceptionScreen'
import { StoredLotCheckScreen } from '../features/logistique/StoredLotCheckScreen'
import { ExpenseScreen } from '../features/depenses/ExpenseScreen'
import { HarvestScreen } from '../features/cultures/HarvestScreen'
import { SemisScreen } from '../features/cultures/SemisScreen'
import { HarvestJournalScreen } from '../features/cultures/HarvestJournalScreen'
import { CropInputScreen } from '../features/cultures/CropInputScreen'
import { CropTransformationScreen } from '../features/cultures/CropTransformationScreen'
import { SlaughterScreen } from '../features/abattoir/SlaughterScreen'
import { ClosureScreen } from '../features/abattoir/ClosureScreen'
import { CuttingScreen } from '../features/abattoir/CuttingScreen'
import { TemperatureRoundScreen } from '../features/abattoir/TemperatureRoundScreen'
import { CleaningRoundScreen } from '../features/abattoir/CleaningRoundScreen'
import { ByproductRoundScreen } from '../features/abattoir/ByproductRoundScreen'
import { SlaughterJournalScreen } from '../features/abattoir/SlaughterJournalScreen'
import { ReceptionScreen } from '../features/abattoir/ReceptionScreen'
import { TemperatureScreen } from '../features/abattoir/TemperatureScreen'
import { CcpScreen } from '../features/abattoir/CcpScreen'
import { CleaningScreen } from '../features/abattoir/CleaningScreen'
import { ByproductScreen } from '../features/abattoir/ByproductScreen'
import { MillCompleteScreen } from '../features/provenderie/MillCompleteScreen'
import { MillStartScreen } from '../features/provenderie/MillStartScreen'
import { MillJournalScreen } from '../features/provenderie/MillJournalScreen'
import { MonEspaceScreen } from '../features/mon-espace/MonEspaceScreen'
import { MaSemaineScreen } from '../features/mon-espace/MaSemaineScreen'
import { WaterRefillScreen } from '../features/ressources/WaterRefillScreen'
import { MeterReadingScreen } from '../features/ressources/MeterReadingScreen'
import { AttendanceScreen } from '../features/rh/AttendanceScreen'
import { AppareilsScreen } from '../features/appareils/AppareilsScreen'
import { TachesScreen } from '../features/taches/TachesScreen'
import { NotificationsScreen } from '../features/notifications/NotificationsScreen'
import { BottomNav } from '../ui/BottomNav'
import { SyncBadge } from '../ui/SyncBadge'
import { UpdateToast } from '../ui/UpdateToast'
import { OpErrorBanner } from '../ui/OpErrorBanner'
import { AccessDenied } from '../ui/AccessDenied'

function headerInitials(name: string | null | undefined): string {
  return (name ?? '?')
    .split(' ')
    .map((w) => w[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
}

/**
 * TOUS les écrans de la PWA, avec le chemin qui porte leur droit d'accès.
 *
 * Le type `RoutePath` vient de ROUTE_ACCESS : ajouter un écran sans y déclarer
 * son droit ne compile pas. Avant cette table, les 50 routes étaient posées
 * une à une sans aucune garde — n'importe quel compte atteignait n'importe quel
 * formulaire en tapant l'URL, et la saisie ne se faisait refuser qu'au serveur.
 */
const SCREENS: Array<[RoutePath, ReactElement]> = [
  ['/', <HomeScreen />],
  ['/nouvelle', <NouvelleSaisieScreen />],
  ['/elevage/pointage/:batchId?', <DailyCheckScreen />],
  ['/elevage/collecte/:batchId', <EggCollectionScreen />],
  ['/elevage/incident/:batchId', <IncidentScreen />],
  ['/elevage/soin/:batchId?', <HealthCareScreen />],
  ['/elevage/couvoir/:incubationId?', <HatcheryScreen />],
  ['/elevage/traite/:batchId?', <MilkingScreen />],
  ['/lot/:batchId', <BatchScreen />],
  ['/scan', <ScanScreen />],
  ['/commerce/vente', <SaleScreen />],
  ['/commerce/journal', <SalesJournalScreen />],
  ['/commerce/encaisser/:saleId?', <PaymentScreen />],
  ['/commerce/retour/:saleId?', <SaleReturnScreen />],
  ['/tresorerie/journal', <TreasuryJournalScreen />],
  ['/provenderie/journal', <MillJournalScreen />],
  ['/abattoir/journal', <SlaughterJournalScreen />],
  ['/cultures/journal', <HarvestJournalScreen />],
  ['/logistique/mouvement', <StockMovementScreen />],
  ['/ressources/ravitaillement', <WaterRefillScreen />],
  ['/ressources/releve', <MeterReadingScreen />],
  ['/rh/presence', <AttendanceScreen />],
  ['/logistique/stocks', <StocksScreen />],
  ['/logistique/inventaire', <InventoryCountScreen />],
  ['/logistique/reception-aliment', <FeedReceptionScreen />],
  ['/logistique/conservation/:lotId?', <StoredLotCheckScreen />],
  ['/depenses/nouvelle', <ExpenseScreen />],
  ['/cultures/semis', <SemisScreen />],
  ['/cultures/recolte/:cycleId', <HarvestScreen />],
  ['/cultures/intrant/:cycleId', <CropInputScreen />],
  ['/cultures/transformation', <CropTransformationScreen />],
  ['/abattoir/execution/:orderId', <SlaughterScreen />],
  ['/abattoir/cloture/:orderId', <ClosureScreen />],
  ['/abattoir/decoupe', <CuttingScreen />],
  ['/abattoir/reception', <ReceptionScreen />],
  ['/abattoir/temperature', <TemperatureScreen />],
  ['/abattoir/temperature/tournee', <TemperatureRoundScreen />],
  ['/abattoir/ccp', <CcpScreen />],
  ['/abattoir/nettoyage', <CleaningScreen />],
  ['/abattoir/nettoyage/tournee', <CleaningRoundScreen />],
  ['/abattoir/sousproduit', <ByproductScreen />],
  ['/abattoir/sousproduit/tournee', <ByproductRoundScreen />],
  ['/provenderie/cloture/:opId', <MillCompleteScreen />],
  ['/provenderie/lancer', <MillStartScreen />],
  ['/alertes', <NotificationsScreen />],
  ['/taches', <TachesScreen />],
  ['/mon-espace', <MonEspaceScreen />],
  ['/mon-espace/semaine', <MaSemaineScreen />],
  ['/appareils', <AppareilsScreen />],
]

/**
 * Garde d'écran. Le droit se lit dans le cache de permissions (hors-ligne) ;
 * la portée personnelle ('@owner') repose sur la fiche employé rattachée.
 */
function Guarded({ path, children }: { path: RoutePath; children: ReactElement }) {
  const { can, me } = useAuth()
  const ok = allows(ROUTE_ACCESS[path], {
    can,
    hasEmployee: me?.scope.employee_id != null,
  })

  return ok ? children : <AccessDenied />
}

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
        <div className="header-actions">
          <SyncBadge />
          <Link to="/mon-espace" className="header-avatar" aria-label={t('Mon espace')}>
            {me.user.avatar_url ? (
              <img src={me.user.avatar_url} alt="" />
            ) : (
              <span className="header-avatar__initials">{headerInitials(me.user.name)}</span>
            )}
          </Link>
        </div>
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
          {SCREENS.map(([path, element]) => (
            <Route key={path} path={path} element={<Guarded path={path}>{element}</Guarded>} />
          ))}
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
        <UpdateToast />
        <OpErrorBanner />
      </BrowserRouter>
    </AuthProvider>
  )
}
