{{--
    LABORATOIRE DE FORMULATION — le calcul temps réel, en UN exemplaire.

    L'écran de création portait sa propre pondération Alpine (deux nutriments) et
    l'écran d'édition son propre `calculateTotal()` (aucun nutriment, seulement le
    total des parts) : on optimisait donc une recette sans voir ses teneurs, là
    précisément où on en a besoin. Les deux écrans appellent maintenant ce module.

    CONTRAT DOM
      • une ligne d'ingrédient        [data-lab-row]
      • sa part en %                  [data-lab-share]        (input)
      • sa matière, si sélectionnable  [data-lab-material]     (select)
        les teneurs sont portées par data-cost / data-n-<clef> sur l'OPTION
        sélectionnée, ou à défaut sur la ligne elle-même ;
      • le sélecteur de norme         [data-lab-norm]          (select)
        cibles portées par data-t-<clef> sur l'option ;
      • sorties : [data-lab-total] [data-lab-cost]
                  [data-lab-real="clef"] [data-lab-target="clef"]
                  [data-lab-bar="clef"] [data-lab-note="clef"]
      • équilibre : [data-lab-status] (texte + couleur), [data-lab-submit]
                    (désactivé hors 100 %), [data-lab-warning] (masqué à 100 %)
--}}
<script>
    window.FormulaLab = (function () {
        const KEYS = @json(array_keys(\App\Models\FoodNorm::NUTRIENTS));
        const DECIMALS = @json(array_map(fn ($n) => $n['decimals'], \App\Models\FoodNorm::NUTRIENTS));
        const NOT_ANALYSED = @json(__('Non analysé'));
        const NO_TARGET = @json(__('pas de cible'));

        /** Attribut dataset correspondant à une clef de nutriment (meth → nMeth). */
        function dataKey(prefix, key) {
            return prefix + key.charAt(0).toUpperCase() + key.slice(1);
        }

        function rowSource(row) {
            const select = row.querySelector('[data-lab-material]');

            if (! select) return row;

            return select.options[select.selectedIndex] || row;
        }

        /** Pondération du mélange + couverture analytique, cf. Formula::nutrientCoverage(). */
        function read(root) {
            const state = { total: 0, cost: 0, nutrients: {}, complete: {}, contributors: 0 };
            KEYS.forEach(k => { state.nutrients[k] = 0; state.complete[k] = true; });

            root.querySelectorAll('[data-lab-row]').forEach(function (row) {
                const input = row.querySelector('[data-lab-share]');
                const share = parseFloat(input && input.value) || 0;

                state.total += share;

                if (share <= 0) return;

                state.contributors++;
                const data = rowSource(row).dataset || {};
                state.cost += (share / 100) * (parseFloat(data.cost) || 0);

                KEYS.forEach(function (key) {
                    const value = parseFloat(data[dataKey('n', key)]) || 0;
                    state.nutrients[key] += (share / 100) * value;

                    // Une teneur à 0 n'a pas été analysée : aucune matière réelle
                    // n'est à 0 kcal ni à 0 % de lysine. On refuse alors de
                    // comparer plutôt que d'afficher une carence de saisie.
                    if (value <= 0) state.complete[key] = false;
                });
            });

            if (state.contributors === 0) {
                KEYS.forEach(k => { state.complete[k] = false; });
            }

            return state;
        }

        function targets(root) {
            const select = root.querySelector('[data-lab-norm]');
            const option = select ? select.options[select.selectedIndex] : null;
            const found = {};

            KEYS.forEach(function (key) {
                const raw = option ? parseFloat(option.dataset[dataKey('t', key)]) : NaN;
                found[key] = isNaN(raw) || raw <= 0 ? null : raw;
            });

            return found;
        }

        function fmt(value, decimals) {
            return value.toLocaleString('fr-FR', {
                minimumFractionDigits: decimals, maximumFractionDigits: decimals,
            });
        }

        /** L'équilibre à 100 % : verrou de soumission, en un seul endroit. */
        function renderBalance(root, total) {
            const balanced = Math.abs(total - 100) < 0.1 && total > 0;

            root.querySelectorAll('[data-lab-status]').forEach(function (el) {
                el.textContent = total.toFixed(2) + '% / 100%';
                el.classList.toggle('text-emerald-500', balanced);
                el.classList.toggle('text-red-500', ! balanced);
            });

            root.querySelectorAll('[data-lab-submit]').forEach(function (el) {
                el.disabled = ! balanced;
            });

            root.querySelectorAll('[data-lab-warning]').forEach(function (el) {
                el.classList.toggle('hidden', balanced);
            });

            return balanced;
        }

        function render(root, state, target) {
            const totalEl = root.querySelector('[data-lab-total]');
            if (totalEl) totalEl.textContent = state.total.toFixed(2) + '%';

            renderBalance(root, state.total);

            const costEl = root.querySelector('[data-lab-cost]');
            if (costEl) costEl.textContent = Math.round(state.cost).toLocaleString('fr-FR');

            KEYS.forEach(function (key, index) {
                const decimals = DECIMALS[index];
                const realEl = root.querySelector('[data-lab-real="' + key + '"]');
                const targetEl = root.querySelector('[data-lab-target="' + key + '"]');
                const barEl = root.querySelector('[data-lab-bar="' + key + '"]');
                const noteEl = root.querySelector('[data-lab-note="' + key + '"]');

                if (realEl) realEl.textContent = fmt(state.nutrients[key], decimals);
                if (targetEl) targetEl.textContent = target[key] === null ? '—' : fmt(target[key], decimals);

                const comparable = state.complete[key] && target[key] !== null;
                const ratio = comparable ? state.nutrients[key] / target[key] : null;

                if (noteEl) {
                    noteEl.textContent = ! state.complete[key] ? NOT_ANALYSED
                        : (target[key] === null ? NO_TARGET : '');
                }

                if (barEl) {
                    barEl.style.width = ratio === null ? '0%' : Math.min(ratio * 100, 100) + '%';
                    barEl.className = barEl.className.replace(/bg-(emerald|amber|red|slate)-\d+/g, '');
                    barEl.classList.add(ratio === null ? 'bg-slate-600'
                        : (ratio < 0.95 ? 'bg-red-500' : (ratio > 1.10 ? 'bg-amber-400' : 'bg-emerald-500')));
                }
            });
        }

        /**
         * @param {Element}  root
         * @param {Function} onState  rappel après chaque recalcul (bouton, alertes…)
         */
        function attach(root, onState) {
            function refresh() {
                const state = read(root);
                render(root, state, targets(root));
                if (onState) onState(state);
            }

            root.addEventListener('input', function (event) {
                if (event.target.closest('[data-lab-row]') || event.target.matches('[data-lab-norm]')) refresh();
            });
            root.addEventListener('change', refresh);

            refresh();

            return refresh;
        }

        return { read, render, renderBalance, targets, attach, KEYS };
    })();
</script>
