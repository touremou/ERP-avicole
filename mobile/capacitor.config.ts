/**
 * Capacitor — PRÉSENT MAIS INACTIF (rampe de secours, cf. RFC §2.2).
 *
 * La PWA est la cible de la Phase 0-2. Si un déclencheur de bascule est
 * atteint (>10 % d'iPhone avec besoin de push, caméra web trop lente sur le
 * parc réel, exigence store), on emballe CE build web en app installable :
 *   npm i @capacitor/core @capacitor/cli && npx cap add android
 * Rien du code src/ n'est à réécrire — les adaptateurs src/platform/
 * fournissent le point de bascule web ↔ natif.
 */
const config = {
  appId: 'com.erpavicole.terrain',
  appName: 'AviTerrain',
  webDir: 'dist',
}

export default config
