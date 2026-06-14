<x-app-layout>
    <div class="py-12 italic font-bold text-left bg-slate-50 min-h-screen">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- GESTION DES ERREURS --}}
            @if ($errors->any())
                <div class="mb-6 p-6 bg-red-600 text-white rounded-[2rem] text-[10px] uppercase font-black shadow-lg animate-pulse">
                    <p class="mb-2 border-b border-white/20 pb-2 italic">{{ __("⚠️ Erreurs de validation :") }}</p>
                    @foreach ($errors->all() as $error) 
                        <p class="mt-1">• {{ $error }}</p> 
                    @endforeach
                </div>
            @endif

            {{-- TITRE DYNAMIQUE --}}
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 {{ isset($eggProduction) ? 'bg-blue-600' : 'bg-emerald-500' }} rounded-3xl flex items-center justify-center text-white shadow-xl rotate-3">
                        <i class="fa-solid {{ isset($eggProduction) ? 'fa-scale-balanced' : 'fa-basket-shopping' }} text-2xl"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                            {{ isset($eggProduction) ? __("✏️ Tri & Calibrage") : __("🥚 Nouvelle Collecte") }}
                        </h2>
                        <p class="text-[10px] font-black text-slate-400 uppercase mt-2 tracking-widest italic leading-none">
                            {{ __("Bande") }} : <span class="text-blue-500">{{ $batch->code }}</span> • {{ $batch->building->name }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- LOGIQUE DE PERMISSION --}}
            @php 
                $canAction = isset($eggProduction) ? auth()->user()->can('M') : auth()->user()->can('C');
            @endphp

            @if($canAction)
                <form action="{{ isset($eggProduction) ? route('egg-productions.update-tri', $eggProduction->id) : route('egg-productions.store') }}" method="POST" class="space-y-6" id="main-form">
                    @csrf
                    @if(isset($eggProduction)) @method('PUT') @endif

                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                    <input type="hidden" name="production_date" value="{{ isset($eggProduction) ? $eggProduction->production_date->format('Y-m-d') : date('Y-m-d') }}">

                    {{-- SECTION 1 : QUANTITÉ BRUTE --}}
                    <div class="bg-white p-10 rounded-[3.5rem] border border-slate-100 shadow-xl space-y-8 relative overflow-hidden text-left">
                        @if(!isset($eggProduction))
                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-4 tracking-widest leading-none italic">{{ __("Plateaux (x30)") }}</label>
                                    <input type="number" id="alv_assist" oninput="calcBrut()" placeholder="0" min="0" class="w-full bg-slate-50 border-none rounded-3xl p-6 font-black text-4xl shadow-inner text-center italic outline-none">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-4 tracking-widest leading-none italic">{{ __("Unités") }}</label>
                                    <input type="number" id="uni_assist" oninput="calcBrut()" placeholder="0" min="0" class="w-full bg-slate-50 border-none rounded-3xl p-6 font-black text-4xl shadow-inner text-center italic outline-none">
                                </div>
                            </div>
                        @endif

                        <div class="pt-8 {{ !isset($eggProduction) ? 'border-t border-slate-50' : '' }} text-center">
                            <label class="block text-[10px] font-black uppercase mb-4 tracking-widest italic {{ isset($eggProduction) ? 'text-blue-500' : 'text-emerald-500' }}">
                                {{ isset($eggProduction) ? __("Correction de la récolte brute (Unités)") : __("Total Œufs Récoltés") }}
                            </label>
                            <input type="number" name="total_eggs_collected" id="total_eggs" 
                                   value="{{ old('total_eggs_collected', $eggProduction->total_eggs_collected ?? '') }}" 
                                   placeholder="0" min="0" required oninput="validateFlow()"
                                   class="w-full {{ isset($eggProduction) ? 'bg-blue-50 text-blue-600' : 'bg-emerald-50 text-emerald-600' }} border-none rounded-[2.5rem] p-8 text-7xl font-black text-center shadow-inner focus:ring-0 italic appearance-none">
                            
                            <p id="info-display" class="mt-4 text-[11px] font-black text-slate-400 uppercase italic tracking-widest">
                                ≈ {{ number_format(($eggProduction->total_eggs_collected ?? 0) / 30, 2) }} {{ __("Alvéoles") }}
                            </p>
                        </div>
                    </div>

                    {{-- SECTION 2 : PERTES (TRI) --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-white p-8 rounded-[2.5rem] border-2 border-red-50 shadow-sm text-left">
                            <label class="block text-[10px] font-black text-red-500 uppercase mb-3 tracking-widest italic leading-none">{{ __("⚠️ Cassés / Fêlés") }}</label>
                            <input type="number" min="0" name="broken_eggs" id="broken" oninput="validateFlow()" 
                                   value="{{ old('broken_eggs', $eggProduction->broken_eggs ?? 0) }}" 
                                   class="w-full bg-red-50/50 border-none rounded-2xl p-4 font-black text-4xl text-red-600 text-center focus:ring-0 italic outline-none">
                        </div>
                        <div class="bg-white p-8 rounded-[2.5rem] border-2 border-orange-50 shadow-sm text-left">
                            <label class="block text-[10px] font-black text-orange-500 uppercase mb-3 tracking-widest italic leading-none">{{ __("⚙️ Anormaux / Sales") }}</label>
                            <input type="number" min="0" name="small_eggs" id="small" oninput="validateFlow()" 
                                   value="{{ old('small_eggs', $eggProduction->small_eggs ?? 0) }}" 
                                   class="w-full bg-orange-50/50 border-none rounded-2xl p-4 font-black text-4xl text-orange-600 text-center focus:ring-0 italic outline-none">
                        </div>
                    </div>

                    {{-- SECTION 3 : CALIBRAGE (UNIQUEMENT SI MODE TRI) --}}
                    @if(isset($eggProduction))
                    <div class="bg-slate-900 p-10 rounded-[4rem] shadow-2xl text-white relative overflow-hidden text-left italic">
                        <div class="flex justify-between items-center mb-8 relative z-10">
                            <h3 class="text-[10px] font-black text-blue-400 uppercase tracking-[0.2em]">{{ __("⚖️ Répartition par Calibre") }}</h3>
                            <span id="validation_feedback" class="text-[9px] font-black uppercase italic px-5 py-2 rounded-2xl transition-all"></span>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 relative z-10">
                            @foreach(['xl' => 'Calibre XL', 'l' => 'Calibre L', 'm' => 'Calibre M', 's' => 'Calibre S'] as $key => $label)
                                <div class="p-6 bg-white/5 rounded-[2rem] border border-white/10">
                                    <label class="block text-[9px] font-black text-slate-500 uppercase mb-4 text-center tracking-widest">{{ __($label) }}</label>
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1">
                                            <input type="number" name="grade_{{ $key }}_alv" id="{{ $key }}_alv" oninput="validateFlow()" 
                                                   value="{{ floor(old('grade_'.$key, $eggProduction->{'grade_'.$key} ?? 0)) }}"
                                                   placeholder="{{ __("Pl.") }}" min="0" class="w-full bg-white/10 border-none rounded-xl p-3 font-black text-2xl text-white text-center focus:ring-2 focus:ring-blue-500 italic">
                                            <p class="text-[7px] text-slate-600 mt-2 text-center uppercase">{{ __("Plat.") }} (x{{ setting('general.eggs_per_tray', 30) }})</p>
                                        </div>
                                        <span class="text-white/20 font-black">+</span>
                                        <div class="flex-1">
                                            <input type="number" name="grade_{{ $key }}_uni" id="{{ $key }}_uni" oninput="validateFlow()" 
                                                   value="{{ round((($eggProduction->{'grade_'.$key} ?? 0) - floor($eggProduction->{'grade_'.$key} ?? 0)) * setting('general.eggs_per_tray', 30)) }}"
                                                   placeholder="{{ __("Unit") }}" min="0" max="29" class="w-full bg-white/10 border-none rounded-xl p-3 font-black text-2xl text-white text-center focus:ring-2 focus:ring-blue-500 italic">
                                            <p class="text-[7px] text-slate-600 mt-2 text-center uppercase">{{ __("Unités") }}</p>
                                        </div>
                                    </div>
                                    <div class="mt-4 pt-4 border-t border-white/5 text-center">
                                        <p class="text-[9px] text-blue-400 font-black uppercase tracking-widest">
                                            {{ __("Total") }} : <span id="count_{{ $key }}" class="text-white text-xs">0</span> {{ __("œufs") }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- ACTIONS --}}
                    <div class="flex flex-col gap-4 pt-6">
                        <button type="submit" id="submit-btn" class="w-full {{ isset($eggProduction) ? 'bg-blue-600' : 'bg-slate-900' }} text-white font-black py-10 rounded-[3rem] shadow-2xl uppercase tracking-[0.3em] text-xs italic transition-all group">
                            <span class="flex items-center justify-center gap-4">
                                {{ isset($eggProduction) ? __("Confirmer le Calibrage") : __("Enregistrer la Récolte") }}
                                <i class="fa-solid fa-check-double group-hover:rotate-12 transition-transform {{ isset($eggProduction) ? 'text-blue-200' : 'text-emerald-400' }}"></i>
                            </span>
                        </button>
                        <a href="{{ route('egg-productions.index') }}" class="w-full bg-white text-slate-400 font-black py-6 rounded-[2.5rem] border border-slate-100 text-center uppercase tracking-[0.3em] text-[9px] italic hover:text-slate-800 transition-all no-underline">
                            {{ __("Annuler & Retour") }}
                        </a>
                    </div>
                </form>
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic">{{ __("Action Verrouillée") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest leading-none mt-2">{{ __("Vous n'avez pas la permission") }} ({{ isset($eggProduction) ? 'M' : 'C' }}) {{ __("requise.") }}</p>
                </div>
            @endif
        </div>
    </div>

    <script>
        function calcBrut() {
            const a = parseInt(document.getElementById('alv_assist')?.value) || 0;
            const u = parseInt(document.getElementById('uni_assist')?.value) || 0;
            const totalInput = document.getElementById('total_eggs');
            if(totalInput) {
                totalInput.value = (a * setting('general.eggs_per_tray', 30)) + u;
                validateFlow();
            }
        }

        function validateFlow() {
            const totalEggs = parseInt(document.getElementById('total_eggs')?.value) || 0;
            const broken = parseInt(document.getElementById('broken')?.value) || 0;
            const small = parseInt(document.getElementById('small')?.value) || 0;
            const submitBtn = document.getElementById('submit-btn');
            const infoDisplay = document.getElementById('info-display');
            const feedback = document.getElementById('validation_feedback');

            if(infoDisplay) infoDisplay.innerText = `≈ ${(totalEggs / setting('general.eggs_per_tray', 30)).toFixed(2)} Alvéoles`;

            // Si nous sommes en mode tri (isset eggProduction via présence de feedback)
            if(feedback && submitBtn) {
                let totalGraded = 0;
                ['xl', 'l', 'm', 's'].forEach(g => {
                    const alv = parseInt(document.getElementById(g + '_alv')?.value) || 0;
                    const uni = parseInt(document.getElementById(g + '_uni')?.value) || 0;
                    const totalForGrade = (alv * setting('general.eggs_per_tray', 30)) + uni;
                    document.getElementById('count_' + g).innerText = totalForGrade;
                    totalGraded += totalForGrade;
                });

                const unitsToGrade = totalEggs - (broken + small);
                const diff = unitsToGrade - totalGraded;

                if (Math.abs(diff) === 0) {
                    feedback.innerText = @json(__("✅ ÉQUILIBRE PARFAIT"));
                    feedback.className = "text-[9px] font-black uppercase italic px-5 py-2 rounded-2xl bg-emerald-500/10 text-emerald-500";
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = "1";
                    submitBtn.classList.add('hover:bg-emerald-600');
                } else {
                    const msg = diff > 0 ? `${@json(__("MANQUE"))} ${Math.abs(diff)}` : `${@json(__("EXCÈS"))} ${Math.abs(diff)}`;
                    feedback.innerText = `⚠️ ${msg} ${@json(__("ŒUFS"))}`;
                    feedback.className = "text-[9px] font-black uppercase italic px-5 py-2 rounded-2xl bg-orange-500/10 text-orange-500";
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = "0.4";
                    submitBtn.classList.remove('hover:bg-emerald-600');
                }
            }
        }

        document.querySelectorAll('input[type=number]').forEach(input => {
            input.addEventListener('input', () => { if(input.value < 0) input.value = 0; });
            input.addEventListener('focus', function() { this.select(); });
        });

        window.onload = validateFlow;
    </script>
</x-app-layout>