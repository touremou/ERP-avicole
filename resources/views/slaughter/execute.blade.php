<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('slaughter.dashboard') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Exécution Abattage") }}</h2>
                <p class="text-[10px] font-black text-rose-600 uppercase tracking-[0.2em] mt-2 italic">{{ $order->order_number }} — {{ $order->batch->code ?? '—' }}</p>
            </div>
        </div>
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
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div><p class="text-[8px] font-black text-rose-400 uppercase">{{ __("Lot") }}</p><p class="text-sm font-black text-slate-900">{{ $order->batch->code ?? '—' }}</p></div>
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
                                <p class="text-[8px] text-slate-400">{{ __("norme") }} : {{ setting('abattoir.yield_target_min', 70) }}-{{ setting('abattoir.yield_target_max', 75) }}%</p>
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
        // ⚙️ INJECTION DYNAMIQUE DES SETTINGS
        const yieldTargetMin = {{ setting('abattoir.yield_target_min', 70) }};
        const yieldAlertMin = {{ setting('abattoir.yield_alert_min', 65) }};

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