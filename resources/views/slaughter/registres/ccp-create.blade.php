<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Relevé CCP')" :subtitle="__('Saisie d\'un point critique — la conformité est tranchée par le serveur')" icon="fa-shield-halved" accent="rose" :back="route('slaughter.registres.ccp')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="ccpForm()" x-cloak>

            @can('abattoir.C')
                @if($errors->any())
                    <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('slaughter.registres.ccp.store') }}">
                    @csrf
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                        <div class="space-y-6">

                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Point critique *") }}</label>
                                <select name="ccp" x-model="ccp" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                    <option value="">{{ __("— Sélectionner —") }}</option>
                                    @foreach(\App\Models\CcpRecord::CCPS as $c)
                                        <option value="{{ $c }}" @selected(old('ccp') === $c)>{{ \App\Models\CcpRecord::labelFor($c) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Ordre d'abattage (optionnel)") }}</label>
                                    <select name="slaughter_order_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="">{{ __("— Aucun —") }}</option>
                                        @foreach($orders as $o)
                                            <option value="{{ $o->id }}" @selected(old('slaughter_order_id') == $o->id)>{{ $o->order_number }} — {{ $o->status }} ({{ $o->planned_date?->format('d/m/Y') }})</option>
                                        @endforeach
                                    </select>
                                    <p class="text-[8px] text-amber-600 ml-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i>{{ __("Un CCP non conforme rattaché à un ordre BLOQUE le lot.") }}</p>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Équipement (réf.)") }}</label>
                                    <input type="text" name="equipment_ref" value="{{ old('equipment_ref') }}" maxlength="50" placeholder="CF-01, Camion-A..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                                </div>
                            </div>

                            {{-- ═══ MESURES DYNAMIQUES SELON LE CCP ═══ --}}

                            {{-- CCP 1 : appréciation ante-mortem --}}
                            <div x-show="ccp === 'ccp1_reception'" x-transition class="p-5 bg-slate-50 rounded-2xl space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest">{{ __("Appréciation ante-mortem *") }}</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="declared_conforme" value="1" x-model="declared" class="peer sr-only" :required="ccp === 'ccp1_reception'">
                                        <div class="p-4 rounded-2xl border-2 text-center transition-all peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500 bg-white border-emerald-100 text-emerald-600">
                                            <span class="text-[9px] font-black uppercase tracking-widest"><i class="fa-solid fa-check mr-1"></i>{{ __("Conforme") }}</span>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="declared_conforme" value="0" x-model="declared" class="peer sr-only">
                                        <div class="p-4 rounded-2xl border-2 text-center transition-all peer-checked:bg-red-600 peer-checked:text-white peer-checked:border-red-600 bg-white border-red-100 text-red-600">
                                            <span class="text-[9px] font-black uppercase tracking-widest"><i class="fa-solid fa-ban mr-1"></i>{{ __("Non conforme") }}</span>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            {{-- CCP 2 : éviscération --}}
                            <div x-show="ccp === 'ccp2_evisceration'" x-transition class="p-5 bg-slate-50 rounded-2xl grid grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest">{{ __("Carcasses contrôlées *") }}</label>
                                    <input type="number" name="carcasses_total" value="{{ old('carcasses_total') }}" min="1" :required="ccp === 'ccp2_evisceration'" class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest">{{ __("Carcasses souillées *") }}</label>
                                    <input type="number" name="carcasses_souillees" value="{{ old('carcasses_souillees') }}" min="0" :required="ccp === 'ccp2_evisceration'" class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                </div>
                                <p class="col-span-2 text-[8px] text-slate-400 m-0">{{ __("Seuil de contamination toléré :") }} {{ setting('abattoir.ccp2_soiled_max_pct') }} %</p>
                            </div>

                            {{-- CCP 3 : refroidissement --}}
                            <div x-show="ccp === 'ccp3_refroidissement'" x-transition class="p-5 bg-slate-50 rounded-2xl space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest">{{ __("Température à cœur (°C) *") }}</label>
                                <input type="number" name="temperature_coeur" value="{{ old('temperature_coeur') }}" step="0.1" min="-60" max="120" :required="ccp === 'ccp3_refroidissement'" class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                <p class="text-[8px] text-slate-400">{{ __("Seuil maximum :") }} {{ setting('abattoir.ccp3_core_temp_max') }} °C</p>
                            </div>

                            {{-- CCP 4 : chaîne du froid --}}
                            <div x-show="ccp === 'ccp4_chaine_froid'" x-transition class="p-5 bg-slate-50 rounded-2xl grid grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest">{{ __("Point de contrôle *") }}</label>
                                    <select name="point" x-model="point" :required="ccp === 'ccp4_chaine_froid'" class="w-full bg-white border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                        <option value="">{{ __("— Sélectionner —") }}</option>
                                        @foreach(array_keys(\App\Models\TemperatureLog::POINTS) as $pt)
                                            @php $b = \App\Models\TemperatureLog::boundsFor($pt); @endphp
                                            <option value="{{ $pt }}" data-bounds="{{ ($b['min'] !== null ? 'min '.$b['min'].'°C ' : '') . ($b['max'] !== null ? 'max '.$b['max'].'°C' : '') }}" @selected(old('point') === $pt)>{{ __(\App\Models\TemperatureLog::POINT_LABELS[$pt] ?? $pt) }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-[8px] text-slate-400" x-show="pointBounds" x-text="'{{ __('Bornes :') }} ' + pointBounds"></p>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest">{{ __("Température (°C) *") }}</label>
                                    <input type="number" name="temperature" value="{{ old('temperature') }}" step="0.1" min="-60" max="120" :required="ccp === 'ccp4_chaine_froid'" class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                                </div>
                            </div>

                            {{-- ACTION CORRECTIVE --}}
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase tracking-widest ml-2" :class="declared === '0' ? 'text-red-500' : 'text-slate-400'">
                                    {{ __("Action corrective") }} <span x-show="declared === '0'">*</span>
                                </label>
                                <textarea name="corrective_action" rows="2" :required="declared === '0'" placeholder="{{ __('Obligatoire si le relevé est non conforme (le serveur re-vérifie la conformité)...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('corrective_action') }}</textarea>
                                <p class="text-[8px] text-slate-400 ml-2"><i class="fa-solid fa-server mr-1"></i>{{ __("La conformité est calculée côté serveur selon les seuils des Réglages — une action corrective sera exigée pour tout relevé non conforme.") }}</p>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-rose-500 hover:bg-rose-600 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] transition-all shadow-2xl italic border-none cursor-pointer">
                        <i class="fa-solid fa-shield-halved mr-2"></i> {{ __("Enregistrer le relevé") }}
                    </button>
                </form>
            @else
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas la permission de saisir un relevé CCP.") }}</p>
                </div>
            @endcan
        </div>
    </div>

    <script>
    function ccpForm() {
        return {
            ccp: @json(old('ccp', '')),
            declared: @json(old('declared_conforme', '')),
            point: @json(old('point', '')),
            get pointBounds() {
                const sel = document.querySelector('select[name="point"]');
                if (!sel || !this.point) return '';
                const opt = sel.options[sel.selectedIndex];
                return opt ? (opt.dataset.bounds || '') : '';
            },
        }
    }
    </script>
</x-app-layout>
