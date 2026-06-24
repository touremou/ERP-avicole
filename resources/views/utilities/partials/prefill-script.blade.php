{{-- Pré-remplissage « comme hier » des formulaires de relevé (eau / énergie).
     $lastData : map kind => (dernier relevé indexé par source_id). Présélectionne
     la source si une seule existe et pré-remplit dès le chargement. --}}
<script>
(function () {
    const RELEVE_LAST = @json($lastData ?? []);

    document.querySelectorAll('[data-prefill-form]').forEach(form => {
        const kind   = form.dataset.prefillForm;
        const select = form.querySelector('[data-prefill-source]');
        if (! select) return;

        const applyPrefill = () => {
            const last = (RELEVE_LAST[kind] || {})[select.value];
            if (! last) return;
            Object.entries(last).forEach(([field, value]) => {
                if (value === null || value === '') return;
                const input = form.querySelector(`[name="${field}"]`);
                // Ne jamais écraser une valeur déjà saisie par l'opérateur.
                if (input && (input.value === '' || input.value === '0')) {
                    input.value = value;
                }
            });
        };

        select.addEventListener('change', applyPrefill);

        // Source unique → présélection + pré-remplissage immédiat (moins de clics).
        const realOptions = [...select.options].filter(o => o.value !== '');
        if (realOptions.length === 1) {
            select.value = realOptions[0].value;
        }
        if (select.value) applyPrefill();
    });
})();
</script>
