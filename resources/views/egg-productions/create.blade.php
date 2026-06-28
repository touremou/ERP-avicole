<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🥚 Nouvelle Collecte')"
                       :subtitle="__('Bande :') . ' ' . $batch->code . ' • ' . $batch->building->name"
                       icon="fa-basket-shopping" accent="emerald"
                       :back="route('egg-productions.index')" />
    </x-slot>

    <div class="py-12 italic font-black text-left bg-slate-50 min-h-screen">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- 1. AFFICHAGE DES ERREURS --}}
            @if ($errors->any())
                <div class="mb-6 p-6 bg-red-600 text-white rounded-[2rem] text-[10px] uppercase font-black shadow-lg animate-pulse">
                    <p class="mb-2 border-b border-white/20 pb-2 italic">{{ __("⚠️ Erreurs de validation :") }}</p>
                    @foreach ($errors->all() as $error)
                        <p class="mt-1">• {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @can('production.C')
                {{-- 3. ALERTE CUMUL OU VERROUILLAGE --}}
                @if(isset($existingToday))
                    @if($existingToday->is_graded)
                        <div class="bg-red-50 p-10 rounded-[3.5rem] border-2 border-red-200 shadow-xl text-center italic relative overflow-hidden">
                            <h3 class="text-xl font-black text-red-700 uppercase mb-2 tracking-tighter">{{ __("Collecte Verrouillée") }}</h3>
                            <p class="text-red-500 text-[11px] font-black uppercase tracking-widest leading-relaxed">
                                {{ __("Les œufs de ce jour (:count) sont déjà triés et en stock.", ['count' => number_format($existingToday->total_eggs_collected, 0)]) }}
                            </p>
                            <a href="{{ route('egg-productions.index') }}" class="inline-block mt-8 px-10 py-4 bg-red-600 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline">{{ __("Retour") }}</a>
                        </div>
                    @else
                        <div class="bg-blue-50 p-8 rounded-[3.5rem] border-2 border-blue-100 shadow-sm flex items-center justify-between gap-6 italic text-left mb-6">
                            <div>
                                <h3 class="font-black uppercase text-blue-700 text-sm tracking-widest">{{ __("Cumul Journalier") }}</h3>
                                <p class="text-[10px] font-black uppercase tracking-widest mt-2 text-blue-500/70">
                                    {{ __("Déjà récolté : :count œufs.", ['count' => number_format($existingToday->total_eggs_collected, 0)]) }}
                                </p>
                            </div>
                            <span class="px-6 py-3 bg-blue-600 text-white rounded-2xl text-[9px] font-black uppercase tracking-widest shadow-lg">{{ __("Addition Auto") }}</span>
                        </div>
                    @endif
                @endif

                {{-- 4. FORMULAIRE (Masqué si verrouillé) --}}
                @if(!isset($existingToday) || !$existingToday->is_graded)
                <form action="{{ route('egg-productions.store') }}" method="POST" class="space-y-6" id="collect-form">
                    @csrf

                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                    <input type="hidden" name="production_date" value="{{ date('Y-m-d') }}">
                    <input type="hidden" name="broken_eggs" value="0">
                    <input type="hidden" name="small_eggs" value="0">

                    <div class="bg-white p-10 rounded-[3.5rem] border border-slate-100 shadow-xl space-y-8 relative overflow-hidden">
                        <div class="grid grid-cols-2 gap-6">
                            <div class="group text-left">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-4 tracking-widest leading-none italic">{{ __("Alvéoles (x30)") }}</label>
                                <input type="number" id="alv" oninput="calc()" placeholder="0" min="0" 
                                       class="w-full bg-slate-50 border-none rounded-3xl p-6 font-black text-4xl shadow-inner focus:bg-white focus:ring-4 focus:ring-emerald-500/10 transition-all text-center italic outline-none">
                            </div>
                            <div class="group text-left">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-4 tracking-widest leading-none italic">{{ __("Unités (Restant)") }}</label>
                                <input type="number" id="uni" oninput="calc()" placeholder="0" min="0" max="29"
                                       class="w-full bg-slate-50 border-none rounded-3xl p-6 font-black text-4xl shadow-inner focus:bg-white focus:ring-4 focus:ring-emerald-500/10 transition-all text-center italic outline-none">
                            </div>
                        </div>

                        <div class="pt-8 border-t border-slate-100 text-center">
                            <label class="block text-[10px] font-black uppercase mb-4 tracking-widest text-emerald-500 italic leading-none">
                                {{ __("Total Œufs Bruts à Enregistrer") }}
                            </label>

                            <input type="number" name="total_eggs_collected" id="total" 
                                   value="{{ old('total_eggs_collected') }}" 
                                   placeholder="0" min="1" required readonly
                                   class="w-full bg-emerald-50 text-emerald-600 border-none rounded-[2.5rem] p-8 text-7xl font-black text-center shadow-inner focus:ring-0 transition-all italic leading-none cursor-not-allowed">
                            
                            <div class="mt-8 flex flex-col items-center gap-3">
                                 <span id="alv-display" class="px-8 py-3 bg-slate-900 text-white rounded-2xl text-[11px] font-black uppercase italic tracking-widest shadow-xl">
                                    {{ __("0.00 Alvéoles") }}
                                 </span>
                                 <span id="laying-rate-badge" class="hidden px-6 py-2 rounded-2xl text-[10px] font-black uppercase italic tracking-widest transition-all"></span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-4 pt-4">
                        <button type="submit" id="submit-btn" class="w-full bg-slate-900 text-white font-black py-10 rounded-[3rem] shadow-2xl uppercase tracking-[0.3em] text-xs italic transition-all hover:bg-emerald-600 active:scale-95 group border-none cursor-pointer">
                            <span class="flex items-center justify-center gap-4">
                                {{ __("Valider la récolte") }}
                                <i class="fa-solid fa-circle-check text-emerald-400 group-hover:scale-125 transition-transform"></i>
                            </span>
                        </button>
                        
                        <a href="{{ route('egg-productions.index') }}" class="w-full bg-white text-slate-400 font-black py-6 rounded-[2.5rem] border border-slate-100 text-center uppercase tracking-[0.3em] text-[9px] italic hover:text-slate-800 transition-all no-underline flex items-center justify-center group">
                            <i class="fa-solid fa-chevron-left mr-2 group-hover:-translate-x-1 transition-transform"></i> {{ __("Annuler & Retour") }}
                        </a>
                    </div>
                </form>
                @endif
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Verrouillé") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic leading-none">{{ __("La permission") }} <span class="text-blue-500">production.C</span> {{ __("(Créer) est requise pour enregistrer la production.") }}</p>
                </div>
            @endcan
        </div>
    </div>

    <script>
        const EGGS_PER_TRAY    = {{ (int) setting('general.eggs_per_tray', 30) ?: 30 }};
        const BATCH_CURRENT_QTY = {{ (int) $batch->current_quantity }};
        const EXISTING_DAY_TOTAL = {{ $existingToday ? (int) $existingToday->total_eggs_collected : 0 }};

        function calc() {
            const a = parseInt(document.getElementById('alv').value) || 0;
            const u = parseInt(document.getElementById('uni').value) || 0;
            const totalInput  = document.getElementById('total');
            const alvDisplay  = document.getElementById('alv-display');
            const rateBadge   = document.getElementById('laying-rate-badge');
            const submitBtn   = document.getElementById('submit-btn');

            if (!totalInput) return;

            const total = (a * EGGS_PER_TRAY) + u;
            totalInput.value = total;

            if (alvDisplay) {
                const decimalAlv = (total / EGGS_PER_TRAY).toFixed(2);
                alvDisplay.innerText = `${decimalAlv} ${@json(__("Alvéoles"))}`;
                alvDisplay.className = (total > 0)
                    ? 'px-8 py-3 bg-emerald-600 text-white rounded-2xl text-[11px] font-black uppercase italic tracking-widest shadow-xl'
                    : 'px-8 py-3 bg-slate-900 text-white rounded-2xl text-[11px] font-black uppercase italic tracking-widest shadow-xl';
            }

            // ── Indicateur taux de ponte ──
            if (rateBadge && BATCH_CURRENT_QTY > 0) {
                const projected = EXISTING_DAY_TOTAL + total;
                const rate      = (projected / BATCH_CURRENT_QTY) * 100;
                rateBadge.classList.remove('hidden');

                if (projected > BATCH_CURRENT_QTY) {
                    rateBadge.textContent  = `⛔ Taux de ponte : ${rate.toFixed(1)} % — Impossible (max 100 %)`;
                    rateBadge.className    = 'px-6 py-2 rounded-2xl text-[10px] font-black uppercase italic tracking-widest bg-red-100 text-red-600';
                    if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.4'; }
                } else if (rate > 85) {
                    rateBadge.textContent  = `⚠️ Taux de ponte : ${rate.toFixed(1)} % — Élevé, vérifiez`;
                    rateBadge.className    = 'px-6 py-2 rounded-2xl text-[10px] font-black uppercase italic tracking-widest bg-amber-100 text-amber-600';
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
                } else if (total > 0) {
                    rateBadge.textContent  = `✅ Taux de ponte : ${rate.toFixed(1)} %`;
                    rateBadge.className    = 'px-6 py-2 rounded-2xl text-[10px] font-black uppercase italic tracking-widest bg-emerald-100 text-emerald-700';
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
                } else {
                    rateBadge.classList.add('hidden');
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
                }
            }
        }

        document.querySelectorAll('input[type=number]').forEach(input => {
            input.addEventListener('input', () => { if(parseFloat(input.value) < 0) input.value = 0; });
            input.addEventListener('focus', function() { this.select(); });
        });

        // ─── 🛰️ MODE TERRAIN : enregistrement hors-ligne de la collecte ───
        // Si le réseau est coupé (ou la base injoignable), on met la collecte
        // en file d'attente IndexedDB ; sync-engine.js la pousse au retour réseau.
        document.getElementById('collect-form')?.addEventListener('submit', async function(e) {
            if (!navigator.onLine || {{ config('app.database_down', false) ? 'true' : 'false' }}) {
                e.preventDefault();
                if (typeof db === 'undefined') {
                    alert(@json(__("Erreur : base locale non initialisée.")));
                    return;
                }

                const formData = new FormData(this);
                const data = Object.fromEntries(formData.entries());

                data.uuid = self.crypto.randomUUID();
                data.is_synced = 0;
                data.batch_id = parseInt(data.batch_id) || data.batch_id;
                data.total_eggs_collected = parseInt(data.total_eggs_collected) || 0;
                data.broken_eggs = parseInt(data.broken_eggs) || 0;
                data.small_eggs = parseInt(data.small_eggs) || 0;
                data.created_at = new Date().toISOString();

                if (data.total_eggs_collected < 1) {
                    alert(@json(__("Veuillez saisir une quantité d'œufs valide.")));
                    return;
                }

                try {
                    await db.egg_productions.add(data);
                    alert(@json(__("🥚 MODE TERRAIN : Collecte de")) + " " + data.total_eggs_collected + " " + @json(__("œufs enregistrée localement.")) + "\n" + @json(__("Elle sera synchronisée au retour du réseau.")));
                    window.location.href = "{{ route('egg-productions.index') }}";
                } catch (err) {
                    console.error("Erreur de stockage local :", err);
                    alert(@json(__("Erreur critique lors de la sauvegarde locale.")));
                }
            }
        });
    </script>
</x-app-layout>