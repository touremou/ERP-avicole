<x-app-layout>
    {{--
        CORRECTION DE LA RÉCOLTE — pas le tri.

        Cet écran ne portait pas son nom : il postait vers `update-tri`, exigeait
        la répartition par calibre pour valider, et jetait le total corrigé. Le
        bouton « Modifier » d'une collecte imposait donc de refaire le tri, et la
        correction elle-même n'était jamais écrite.

        Compter les œufs sortis du bâtiment et les répartir par calibre sont deux
        gestes distincts, faits à deux moments, souvent par deux personnes.
        Le calibrage a son propre écran : egg-productions/tri.blade.php.
    --}}
    <x-slot name="header">
        <x-page-header :title="__('✏️ Corriger la récolte')"
                       :subtitle="__('Bande') . ' : ' . $batch->code . ' • ' . $batch->building->name"
                       icon="fa-pen"
                       accent="blue"
                       :back="route('egg-productions.index')" />
    </x-slot>

    <div class="py-12 italic font-bold text-left bg-slate-50 min-h-screen">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- GESTION DES ERREURS --}}
            @if ($errors->any())
                <div class="mb-6 p-6 bg-red-600 text-white rounded-[2rem] text-[10px] uppercase font-black shadow-lg">
                    <p class="mb-2 border-b border-white/20 pb-2 italic">{{ __("⚠️ Erreurs de validation :") }}</p>
                    @foreach ($errors->all() as $error)
                        <p class="mt-1">• {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if(auth()->user()->can('M'))
                {{-- AVERTISSEMENT : journée déjà triée --}}
                @if($eggProduction->is_graded)
                    <div class="p-6 bg-amber-50 border-2 border-amber-200 rounded-[2rem] text-left">
                        <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest leading-none m-0">
                            {{ __("⚠️ Cette journée a déjà été triée") }}
                        </p>
                        <p class="text-[10px] text-amber-600 mt-3 leading-relaxed normal-case m-0">
                            {{ __("Corriger la récolte rouvrira le tri : les alvéoles entrées en magasin en seront ressorties, et la journée reviendra en réserve brute à recalibrer. Si rien ne change, rien n'est défait.") }}
                        </p>
                    </div>
                @endif

                <form action="{{ route('egg-productions.update', $eggProduction->id) }}" method="POST" class="space-y-6" id="main-form">
                    @csrf
                    @method('PUT')

                    {{-- SECTION 1 : RÉCOLTE BRUTE --}}
                    <div class="bg-white p-10 rounded-[3.5rem] border border-slate-100 shadow-xl space-y-8 relative overflow-hidden text-left">
                        <div class="text-center">
                            <label class="block text-[10px] font-black uppercase mb-4 tracking-widest italic text-blue-500">
                                {{ __("Correction de la récolte brute (Unités)") }}
                            </label>
                            <input type="number" name="total_eggs_collected" id="total_eggs"
                                   value="{{ old('total_eggs_collected', $eggProduction->total_eggs_collected) }}"
                                   placeholder="0" min="0" required oninput="majResume()"
                                   class="w-full bg-blue-50 text-blue-600 border-none rounded-[2.5rem] p-8 text-7xl font-black text-center shadow-inner focus:ring-0 italic appearance-none">

                            <p id="info-display" class="mt-4 text-[11px] font-black text-slate-400 uppercase italic tracking-widest">
                                ≈ {{ number_format(\App\Services\UnitConverter::eggsToTrays($eggProduction->total_eggs_collected ?? 0), 2) }} {{ __("Alvéoles") }}
                            </p>
                        </div>
                    </div>

                    {{-- SECTION 2 : PERTES --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-white p-8 rounded-[2.5rem] border-2 border-red-50 shadow-sm text-left">
                            <label class="block text-[10px] font-black text-red-500 uppercase mb-3 tracking-widest italic leading-none">{{ __("⚠️ Cassés / Fêlés") }}</label>
                            <input type="number" min="0" name="broken_eggs" id="broken" oninput="majResume()"
                                   value="{{ old('broken_eggs', $eggProduction->broken_eggs ?? 0) }}"
                                   class="w-full bg-red-50/50 border-none rounded-2xl p-4 font-black text-4xl text-red-600 text-center focus:ring-0 italic outline-none">
                        </div>
                        <div class="bg-white p-8 rounded-[2.5rem] border-2 border-orange-50 shadow-sm text-left">
                            <label class="block text-[10px] font-black text-orange-500 uppercase mb-3 tracking-widest italic leading-none">{{ __("⚙️ Anormaux / Sales") }}</label>
                            <input type="number" min="0" name="small_eggs" id="small" oninput="majResume()"
                                   value="{{ old('small_eggs', $eggProduction->small_eggs ?? 0) }}"
                                   class="w-full bg-orange-50/50 border-none rounded-2xl p-4 font-black text-4xl text-orange-600 text-center focus:ring-0 italic outline-none">
                        </div>
                    </div>

                    {{-- SECTION 3 : OBSERVATIONS --}}
                    <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm text-left">
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest italic leading-none">{{ __("Observations") }}</label>
                        <textarea name="observations" rows="3" maxlength="500"
                                  class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[11px] font-bold text-slate-600 focus:ring-0 italic outline-none">{{ old('observations', $eggProduction->observations) }}</textarea>
                    </div>

                    {{-- RÉSUMÉ : œufs sains restant à calibrer --}}
                    <div class="bg-slate-900 p-8 rounded-[2.5rem] text-white text-center italic">
                        <p class="text-[9px] font-black text-slate-500 uppercase tracking-[0.2em] m-0">{{ __("Œufs sains à calibrer") }}</p>
                        <p id="sains" class="text-4xl font-black m-0 mt-2">—</p>
                        <p id="alerte-pertes" class="text-[9px] font-black text-red-400 uppercase tracking-widest mt-3 m-0 hidden">
                            {{ __("⚠️ Les pertes dépassent la récolte") }}
                        </p>
                    </div>

                    {{-- ACTIONS --}}
                    <div class="flex flex-col gap-4 pt-6">
                        <button type="submit" id="submit-btn" class="w-full bg-blue-600 text-white font-black py-10 rounded-[3rem] shadow-2xl uppercase tracking-[0.3em] text-xs italic transition-all group">
                            <span class="flex items-center justify-center gap-4">
                                {{ __("Enregistrer la correction") }}
                                <i class="fa-solid fa-check-double group-hover:rotate-12 transition-transform text-blue-200"></i>
                            </span>
                        </button>
                        <a href="{{ route('egg-productions.tri', $eggProduction->id) }}" class="w-full bg-white text-blue-500 font-black py-6 rounded-[2.5rem] border border-blue-100 text-center uppercase tracking-[0.3em] text-[9px] italic hover:bg-blue-50 transition-all no-underline">
                            {{ __("⚖️ Aller au calibrage") }}
                        </a>
                        <a href="{{ route('egg-productions.index') }}" class="w-full bg-white text-slate-400 font-black py-6 rounded-[2.5rem] border border-slate-100 text-center uppercase tracking-[0.3em] text-[9px] italic hover:text-slate-800 transition-all no-underline">
                            {{ __("Annuler & Retour") }}
                        </a>
                    </div>
                </form>
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic">{{ __("Action Verrouillée") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest leading-none mt-2">{{ __("Vous n'avez pas la permission") }} (M) {{ __("requise.") }}</p>
                </div>
            @endif
        </div>
    </div>

    <script>
        const EGGS_PER_TRAY = {{ (int) setting('general.eggs_per_tray', 30) ?: 30 }};

        function majResume() {
            const total  = parseInt(document.getElementById('total_eggs')?.value) || 0;
            const broken = parseInt(document.getElementById('broken')?.value) || 0;
            const small  = parseInt(document.getElementById('small')?.value) || 0;

            const info = document.getElementById('info-display');
            if (info) info.innerText = `≈ ${(total / EGGS_PER_TRAY).toFixed(2)} Alvéoles`;

            const sains = total - (broken + small);
            document.getElementById('sains').innerText = sains;

            // Un œuf cassé fait partie des œufs ramassés : les pertes ne peuvent
            // pas dépasser la récolte. Le serveur le refuse aussi.
            const alerte = document.getElementById('alerte-pertes');
            const btn    = document.getElementById('submit-btn');
            if (sains < 0) {
                alerte.classList.remove('hidden');
                btn.disabled = true;
                btn.style.opacity = "0.4";
            } else {
                alerte.classList.add('hidden');
                btn.disabled = false;
                btn.style.opacity = "1";
            }
        }

        document.querySelectorAll('input[type=number]').forEach(input => {
            input.addEventListener('input', () => { if (input.value < 0) input.value = 0; });
            input.addEventListener('focus', function () { this.select(); });
        });

        window.onload = majResume;
    </script>
</x-app-layout>
