import './bootstrap';

// Figtree auto-hébergée (bundlée par Vite) — plus de dépendance au CDN
// fonts.bunny.net. Poids 400/500/600, comme le chargement CDN d'origine.
import '@fontsource/figtree/400.css';
import '@fontsource/figtree/500.css';
import '@fontsource/figtree/600.css';

import Alpine from 'alpinejs';
import './sync-engine';
import { brancherRetour } from './back-navigation';
import { brancherModales } from './modal-reopen';
import { afficherRatio } from './breeding-ratio';

// Les flèches « Retour » (`a[data-back]`) ramènent à la page précédente.
brancherRetour();

// Une saisie refusée rouvre sa fenêtre modale (`[data-modale]`), remplie.
brancherModales();

// Proportion de reproducteurs, jugée selon l'espèce (création, modification,
// planification de bande).
window.afficherRatioReproducteurs = (males, femelles, slugEspece) =>
    afficherRatio(document, males, femelles, (window.AVISMART_RATIOS ?? {})[slugEspece] ?? null);

window.Alpine = Alpine;

Alpine.start();
