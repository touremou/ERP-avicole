import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Palette des modules (couleur stockée en base : modules.color) utilisée
    // dynamiquement dans le lanceur de modules de la navigation. Comme ces
    // classes sont construites par interpolation (bg-{color}-50), elles ne
    // sont pas détectables par le scan JIT et doivent être listées ici.
    safelist: [
        {
            pattern: /(bg|text)-(slate|blue|amber|lime|indigo|rose|teal|orange|cyan|emerald|pink|purple|violet|red)-(50|500)/,
            variants: ['hover'],
        },
        // Nuances de vert appliquées dynamiquement via des ternaires Blade
        // (ex. la barre d'occupation de la parcelle : bg-green-600 / bg-green-400
        // selon le taux). Le scan JIT peut les manquer ; on les garantit ici
        // pour que le remplissage de la barre conserve toujours sa couleur.
        {
            pattern: /(bg|text)-green-(100|200|400|600|700)/,
        },
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [
        forms,
        // Variante `can-hover:` — ne s'applique que sur les périphériques
        // disposant d'un vrai survol (souris/trackpad), jamais sur écran
        // tactile. On l'utilise pour révéler des actions au survol sans
        // imposer le double-tap aux utilisateurs sur tablette/mobile (où les
        // actions restent visibles en permanence).
        plugin(function ({ addVariant }) {
            addVariant('can-hover', '@media (hover: hover) and (pointer: fine)');
        }),
    ],
};
