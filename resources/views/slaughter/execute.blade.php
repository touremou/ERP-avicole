<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Exécution Abattage')" :subtitle="$order->order_number . ' — ' . ($order->batch->code ?? '—')" icon="fa-industry" accent="rose" :back="route('slaughter.dashboard')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="slaughterForm()" x-cloak>
            
            {{-- 🔒 SÉCURITÉ : Vérification de la permission de Modification (Exécution) --}}
            @can('abattoir.M')
                @if($errors->any())
                    <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
                @endif

                {{-- RECAP ORDRE --}}
                <div class="bg-rose-50 p-6 rounded-[2.5rem] border border-rose-200 mb-6">
                    <div class="grid grid-cols-4 gap-4 text-center">
                        <div><p class="text-[8px] font-black text-rose-400 uppercase">{{ __("Lot") }}</p><p class="text-sm font-black text-slate-900">{{ $order->batch->code ?? '—' }}</p></div>
                        <div><p class="text-[8px] font-black text-rose-400 uppercase">{{ __("Espèce") }}</p><p class="text-sm font-black text-slate-900">{{ $order->batch->species->name_fr ?? __('Poulet') }}</p></div>
                        <div><p class="text-[8px] font-black text-rose-400 uppercase">{{ __("Prévu") }}</p><p class="text-sm font-black text-slate-900">{{ __(":qty sujets", ['qty' => $order->planned_quantity]) }}</p></div>
                        <div><p class="text-[8px] font-black text-rose-400 uppercase">{{ __("Bâtiment") }}</p><p class="text-sm font-black text-slate-900">{{ $order->batch->building->name ?? '—' }}</p></div>
                    </div>
                </div>

                <form method="POST" action="{{ route('slaughter.execute.store', $order) }}">
                    @csrf
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2"><i class="fa-solid fa-scale-balanced text-rose-500"></i> {{ __("Pesées & Résultats") }}</h3>
                        <div class="grid grid-cols-2 gap-6 mb-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Sujets abattus") }} *</label>
                                <input type="number" name="actual_quantity" x-model.number="actualQty" min="1" max="{{ $order->batch->current_quantity ?? 99999 }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                            </div>
                            <div class="space-y-2">
                                {{-- Remarque: Format HTML natif exigé pour <input type="date"> --}}
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date d'exécution") }} *</label>
                                <input type="date" name="execution_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-6 mb-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-emerald-600 tracking-widest ml-2">{{ __("Poids vif total") }} ({{ setting('general.weight_unit', 'kg') }}) *</label>
                                <input type="number" name="total_live_weight_kg" x-model.number="liveWeight" step="0.1" min="0.1" required class="w-full bg-emerald-50 border-2 border-emerald-200 rounded-2xl p-4 text-lg font-black text-emerald-600 outline-none text-center">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-blue-600 tracking-widest ml-2">{{ __("Poids carcasse total") }} ({{ setting('general.weight_unit', 'kg') }}) *</label>
                                <input type="number" name="total_carcass_weight_kg" x-model.number="carcassWeight" step="0.1" min="0.1" required class="w-full bg-blue-50 border-2 border-blue-200 rounded-2xl p-4 text-lg font-black text-blue-600 outline-none text-center">
                            </div>
                        </div>

                        {{-- KPI CALCULÉS EN TEMPS RÉEL --}}
                        <div class="grid grid-cols-3 gap-4 mb-6" x-show="liveWeight > 0 && carcassWeight > 0">
                            <div class="bg-slate-50 p-4 rounded-2xl text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Rendement carcasse") }}</p>
                                {{-- ⚙️ PARAMÉTRAGE DYNAMIQUE (via Alpine) --}}
                                <p class="text-xl font-black" :class="yieldPercent >= yieldTargetMin ? 'text-emerald-600' : (yieldPercent >= yieldAlertMin ? 'text-amber-600' : 'text-red-600')" x-text="yieldPercent + '%'"></p>
                                <p class="text-[8px] text-slate-400">{{ __("norme") }} {{ $order->batch->species->name_fr ?? __('Poulet') }} : {{ $yield['target_min'] }}-{{ $yield['target_max'] }}%</p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-2xl text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Poids moyen vif") }}</p>
                                <p class="text-xl font-black text-slate-900" x-text="avgLive + ' {{ setting('general.weight_unit', 'kg') }}'"></p>
                            </div>
                            <div class="bg-slate-50 p-4 rounded-2xl text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Poids moyen carcasse") }}</p>
                                <p class="text-xl font-black text-slate-900" x-text="avgCarcass + ' {{ setting('general.weight_unit', 'kg') }}'"></p>
                            </div>
                        </div>

                        {{-- SAISIES SANITAIRES --}}
                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-red-500 tracking-widest ml-2">{{ __("Saisies sanitaires") }}</label>
                                <input type="number" name="condemned_count" value="0" min="0" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none text-center">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Motif de saisie") }}</label>
                                <input type="text" name="condemned_reason" placeholder="{{ __('Abcès, cachexie...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                            </div>
                        </div>
                        <div class="mt-6 space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Notes inspecteur") }}</label>
                            <textarea name="inspector_notes" rows="2" placeholder="{{ __('Observations sanitaires...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none"></textarea>
                        </div>

                        {{-- CCP 3 dans le même geste (anti-corvée) : évite le second
                             écran registre + l'alerte « CCP 3 manquant » du soir. --}}
                        <div class="mt-6 p-5 bg-rose-50/60 border border-rose-100 rounded-[2rem]" x-data="{ ccp3: '{{ old('ccp3_core_temp') }}' }">
                            <p class="text-[9px] font-black uppercase tracking-widest text-rose-600 mb-3"><i class="fa-solid fa-shield-halved mr-1"></i> {{ __("CCP 3 — T° à cœur après refroidissement (recommandé)") }}</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Température à cœur (°C)") }}</label>
                                    <input type="number" name="ccp3_core_temp" x-model="ccp3" step="0.1" min="-10" max="60" value="{{ old('ccp3_core_temp') }}" placeholder="≤ {{ setting('abattoir.ccp3_core_temp_max', 4) }} °C" class="w-full bg-white border border-rose-100 rounded-2xl p-4 text-lg font-black text-rose-600 outline-none text-center">
                                </div>
                                <div class="space-y-2" x-show="ccp3 !== '' && parseFloat(ccp3) > {{ (float) setting('abattoir.ccp3_core_temp_max', 4) }}" x-transition>
                                    <label class="text-[9px] font-black uppercase text-red-600 tracking-widest ml-2">{{ __("Hors seuil — action corrective *") }}</label>
                                    <textarea name="ccp3_corrective_action" rows="2" maxlength="2000" placeholder="{{ __('Carcasses replongées en bac glacé, re-contrôle à 30 min...') }}" class="w-full bg-white border border-red-200 rounded-2xl p-4 text-xs font-bold outline-none">{{ old('ccp3_corrective_action') }}</textarea>
                                    <p class="text-[8px] text-red-500 ml-2 m-0">{{ __("Le lot sera BLOQUÉ automatiquement (RG-02) — libération réservée au niveau qualité.") }}</p>
                                </div>
                            </div>
                            <p class="text-[8px] text-slate-400 mt-2 mb-0 ml-2">{{ __("Renseigné ici = relevé CCP 3 créé automatiquement au registre, plus rien à ressaisir.") }}</p>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-rose-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-rose-600 transition-all shadow-2xl italic border-none cursor-pointer">
                        <i class="fa-solid fa-check-double mr-2"></i> {{ __("Valider l'Abattage & Mettre en Stock") }}
                    </button>
                </form>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas la permission d'exécuter un ordre d'abattage.") }}</p>
                </div>
            @endcan
        </div>
    </div>

    <script>
    function slaughterForm() {
        // ⚙️ BANDES DE RENDEMENT CARCASSE PROPRES À L'ESPÈCE (config/butchery.php)
        const yieldTargetMin = {{ $yield['target_min'] }};
        const yieldAlertMin = {{ $yield['alert_min'] }};

        return {
            actualQty: {{ $order->planned_quantity }}, 
            liveWeight: 0, 
            carcassWeight: 0,
            
            yieldTargetMin: yieldTargetMin,
            yieldAlertMin: yieldAlertMin,

            get yieldPercent() { return this.liveWeight > 0 ? (this.carcassWeight / this.liveWeight * 100).toFixed(1) : '—'; },
            get avgLive() { return this.actualQty > 0 ? (this.liveWeight / this.actualQty).toFixed(3) : '—'; },
            get avgCarcass() { return this.actualQty > 0 ? (this.carcassWeight / this.actualQty).toFixed(3) : '—'; },
        }
    }
    </script>
</x-app-layout>