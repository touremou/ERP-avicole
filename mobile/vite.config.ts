import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// PWA compagnon terrain ERP-avicole.
// - App-shell précaché (offline par défaut) ; les données passent par Dexie,
//   PAS par le cache HTTP (le service worker ne cache jamais /api).
// - En dev, proxy vers le Laravel local pour éviter le CORS.
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        // Jamais de cache HTTP sur l'API : la vérité offline vit dans Dexie
        // (miroir + outbox), pas dans le cache du service worker.
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [],
      },
      manifest: {
        name: 'Biocrest — AviTerrain',
        short_name: 'Biocrest',
        description: 'Application compagnon terrain (pointages, ventes, stocks) — hors-ligne par défaut',
        lang: 'fr',
        display: 'standalone',
        orientation: 'portrait',
        theme_color: '#349937',
        background_color: '#f4f6f5',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
    }),
  ],
  server: {
    proxy: {
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
  build: {
    // Budget terrain : app-shell léger (cf. RFC §4 règle 10).
    chunkSizeWarningLimit: 250,
  },
})
