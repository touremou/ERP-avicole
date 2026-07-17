import './bootstrap';

// Figtree auto-hébergée (bundlée par Vite) — plus de dépendance au CDN
// fonts.bunny.net. Poids 400/500/600, comme le chargement CDN d'origine.
import '@fontsource/figtree/400.css';
import '@fontsource/figtree/500.css';
import '@fontsource/figtree/600.css';

import Alpine from 'alpinejs';
import './sync-engine';

window.Alpine = Alpine;

Alpine.start();
