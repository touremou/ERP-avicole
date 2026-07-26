@php
    /**
     * Bloc CONTRAT partagé par l'embauche et la fiche — type + terme.
     *
     * Partagé volontairement : c'est la divergence entre les deux formulaires
     * qui a laissé passer des champs affichés mais jamais validés. Une seule
     * définition, deux usages.
     *
     * $employee est null à l'embauche.
     */
    $current = old('contract_type', $employee->contract_type ?? 'CDI');
    $currentEnd = old('contract_end_date', optional($employee->contract_end_date ?? null)->format('Y-m-d'));
    $fixed = in_array($current, ['CDD', 'Journalier'], true);
    $field = 'w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner font-black text-slate-800 italic';
@endphp

<div class="space-y-3">
    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Type de Contrat") }}</label>
    <select name="contract_type" id="contract_type" required
            class="{{ $field }} appearance-none cursor-pointer">
        <option value="CDI" {{ $current == 'CDI' ? 'selected' : '' }}>📄 {{ __("CDI (Indéterminé)") }}</option>
        <option value="CDD" {{ $current == 'CDD' ? 'selected' : '' }}>⏳ {{ __("CDD (Déterminé)") }}</option>
        <option value="Journalier" {{ $current == 'Journalier' ? 'selected' : '' }}>☀️ {{ __("Journalier") }}</option>
    </select>
</div>

{{-- Le terme. Masqué sur un CDI, qui n'en a pas ; obligatoire dès qu'il y en a
     un, car sans terme rien ne peut signaler l'échéance — donc rien ne
     déclenche la décision de prolonger ou de notifier la fin. --}}
<div class="space-y-3" id="contract_end_wrap" @class(['hidden' => ! $fixed])>
    <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">
        {{ __("Fin du contrat") }} <span class="text-rose-500">*</span>
    </label>
    <input type="date" name="contract_end_date" id="contract_end_date" value="{{ $currentEnd }}"
           class="{{ $field }}">
    <p class="text-[9px] font-bold text-slate-400 uppercase italic ml-1 leading-relaxed">
        {{ __("Un rappel s'affichera :days jours avant l'échéance pour prolonger ou émettre le préavis.", ['days' => (int) setting('rh.contract_notice_days', 30)]) }}
    </p>
    @error('contract_end_date')
        <p class="text-[10px] font-black text-rose-600 uppercase italic ml-1">{{ $message }}</p>
    @enderror
</div>

<script>
    // Le terme n'existe que sur un contrat à durée déterminée : on le montre et
    // on le rend requis en même temps, pour que le navigateur bloque avant le
    // serveur. Sur un CDI, on VIDE le champ — un terme resté d'un choix
    // précédent serait refusé côté serveur (prohibited_if) sans que le
    // responsable comprenne pourquoi, le champ étant masqué.
    (function () {
        const type = document.getElementById('contract_type')
        const wrap = document.getElementById('contract_end_wrap')
        const input = document.getElementById('contract_end_date')
        if (!type || !wrap || !input) return

        function refresh() {
            const fixed = type.value === 'CDD' || type.value === 'Journalier'
            wrap.classList.toggle('hidden', !fixed)
            input.required = fixed
            if (!fixed) input.value = ''
        }

        type.addEventListener('change', refresh)
        refresh()
    })()
</script>
