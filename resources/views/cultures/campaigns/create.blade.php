<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-calendar-week text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Nouvelle campagne") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Planification d'une saison culturale") }}</p>
                </div>
            </div>
            <a href="{{ route('crop-campaigns.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-xmark mr-2"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <form action="{{ route('crop-campaigns.store') }}" method="POST"
                      x-data="campaignForm()"
                      class="lg:col-span-2 bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                            <input type="text" name="code" value="{{ old('code', 'CAM-'.now()->year.'-') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic uppercase">
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Année *") }}</label>
                            <input type="number" name="year" min="2020" max="2035" x-model="year" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de la campagne *") }}</label>
                            <input type="text" name="name" x-model="name" @input="markNameEdited()" required placeholder="{{ __('Grande saison pluies '.now()->year) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Saison *") }}</label>
                            <select name="season" x-model="season" @change="onSeasonChange()" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                                @foreach($seasons as $key => $s)
                                    <option value="{{ $key }}" @selected(old('season') == $key)>{{ $s['label'] }} ({{ $s['months'] }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Statut") }}</label>
                            <select name="status" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                                @foreach($statuses as $key => $label)
                                    <option value="{{ $key }}" @selected(old('status') == $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date début *") }}</label>
                            <input type="date" name="start_date" x-model="startDate" @change="onStartDateChange()" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date fin prévue") }}</label>
                            <input type="date" name="end_date_planned" x-model="endDate" value="{{ old('end_date_planned') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Objectif de production (tonnes)") }}</label>
                            <input type="number" step="0.1" min="0" name="target_production_t" value="{{ old('target_production_t') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                            <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                    <div class="flex justify-end pt-4 border-t border-slate-50">
                        <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                            <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Créer la campagne") }}
                        </button>
                    </div>
                </form>

                <div class="bg-white p-6 rounded-[3rem] border border-slate-100 shadow-sm h-fit">
                    <h3 class="text-[10px] font-black uppercase text-slate-700 tracking-widest italic mb-4"><i class="fa-solid fa-cloud-sun-rain text-green-500 mr-1"></i> {{ __("Saisons de Guinée") }}</h3>
                    @foreach($seasons as $key => $s)
                        <div class="flex items-start gap-2 mb-4">
                            <span class="shrink-0 mt-0.5 text-[8px] font-black uppercase bg-{{ $s['color'] }}-100 text-{{ $s['color'] }}-700 px-2 py-1 rounded-full">{{ $s['months'] }}</span>
                            <p class="text-[10px] font-black uppercase text-slate-700 italic leading-tight">{{ $s['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <script>
        function campaignForm() {
            return {
                // Référentiel des saisons guinéennes (mois → saison + libellé + fin).
                seasons: {
                    grande_saison_pluies: { label: 'Grande saison pluies', endMonth: 10 },
                    petite_saison:        { label: 'Petite saison',        endMonth: 12 },
                    saison_seche:         { label: 'Saison sèche',          endMonth: 4  },
                },
                startDate: @js(old('start_date', now()->toDateString())),
                endDate: @js(old('end_date_planned', '')),
                season: @js(old('season', '')),
                year: @js(old('year', now()->year)),
                name: @js(old('name', '')),
                autoName: true,

                init() {
                    // Si aucune saison choisie, on la déduit de la date de début.
                    if (!this.season) this.detectSeason();
                },

                /** Déduit la saison du mois de la date de début (climat guinéen). */
                seasonFromMonth(month) {
                    if (month >= 5 && month <= 10) return 'grande_saison_pluies';
                    if (month >= 11) return 'petite_saison';
                    return 'saison_seche'; // janv. – avr.
                },

                detectSeason() {
                    if (!this.startDate) return;
                    const d = new Date(this.startDate);
                    this.season = this.seasonFromMonth(d.getMonth() + 1);
                    this.afterSeasonResolved(d);
                },

                onStartDateChange() {
                    const d = new Date(this.startDate);
                    this.year = d.getFullYear();
                    this.season = this.seasonFromMonth(d.getMonth() + 1);
                    this.afterSeasonResolved(d);
                },

                onSeasonChange() {
                    // L'utilisateur fixe la saison manuellement : on garde le nom auto si non édité.
                    if (this.startDate) this.afterSeasonResolved(new Date(this.startDate));
                },

                /** Calcule la date de fin prévue + propose un nom de campagne cohérent. */
                afterSeasonResolved(d) {
                    const meta = this.seasons[this.season];
                    if (!meta) return;
                    // Fin prévue = dernier jour du mois de fin de saison de l'année courante.
                    const end = new Date(d.getFullYear(), meta.endMonth, 0);
                    if (!this.endDate) this.endDate = end.toISOString().slice(0, 10);
                    if (this.autoName) this.name = meta.label + ' ' + d.getFullYear();
                },

                // Dès que l'utilisateur édite le nom, on cesse de l'écraser.
                markNameEdited() { this.autoName = false; },
            };
        }
    </script>
</x-app-layout>
