<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Laboratoire & Diagnostics')" :subtitle="__('Suivi des anomalies sanitaires et autopsies')" icon="fa-microscope" accent="purple" />
    </x-slot>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- KPIs sanitaires --}}
            @isset($stats)
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Incidents ouverts") }}</p>
                    <p class="text-3xl font-black text-slate-800 tracking-tighter">{{ $stats['open'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Critiques ouverts") }}</p>
                    <p class="text-3xl font-black {{ $stats['critical'] > 0 ? 'text-rose-600' : 'text-slate-800' }} tracking-tighter">{{ $stats['critical'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Diagnostic en retard") }} <span class="text-slate-300">(>{{ $stats['sla_days'] }}j)</span></p>
                    <p class="text-3xl font-black {{ $stats['overdue'] > 0 ? 'text-amber-600' : 'text-slate-800' }} tracking-tighter">{{ $stats['overdue'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Coût traitements") }}</p>
                    <p class="text-2xl font-black text-slate-800 tracking-tighter">{{ number_format($stats['cost'], 0, ',', ' ') }} <span class="text-[10px] text-slate-400">{{ currency() }}</span></p>
                </div>
            </div>
            @endisset

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse($incidents as $incident)
                    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden flex flex-col">
                        
                        {{-- En-tête de la carte (Statut) --}}
                        <div @class([
                            'p-4 flex justify-between items-center border-b',
                            'bg-rose-50 border-rose-100' => $incident->status === 'en_attente',
                            'bg-amber-50 border-amber-100' => $incident->status === 'diagnostique',
                            'bg-emerald-50 border-emerald-100' => $incident->status === 'resolu',
                        ])>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span @class([
                                    'px-3 py-1 rounded-xl text-[9px] font-black uppercase tracking-widest border',
                                    'bg-rose-600 text-white border-rose-700 animate-pulse' => $incident->status === 'en_attente',
                                    'bg-amber-100 text-amber-700 border-amber-200' => $incident->status === 'diagnostique',
                                    'bg-emerald-100 text-emerald-700 border-emerald-200' => $incident->status === 'resolu',
                                ])>
                                    {{ str_replace('_', ' ', $incident->status) }}
                                </span>
                                {{-- Gravité --}}
                                <span class="px-2 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest bg-{{ $incident->severity_color }}-100 text-{{ $incident->severity_color }}-700 border border-{{ $incident->severity_color }}-200">
                                    {{ $incident->severity_label }}
                                </span>
                                @if($incident->is_quarantined)
                                    <span class="px-2 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest bg-purple-100 text-purple-700 border border-purple-200" title="{{ __('Lot isolé') }}">
                                        <i class="fa-solid fa-shield-virus mr-1"></i>{{ __('Quarantaine') }}
                                    </span>
                                @endif
                            </div>
                            <a href="{{ route('health.incidents.show', $incident->id) }}" class="text-[10px] text-slate-400 font-black no-underline hover:text-slate-700 flex items-center gap-1" title="{{ __('Voir le détail') }}">
                                {{ $incident->created_at->format('d/m/Y H:i') }}
                                <i class="fa-solid fa-up-right-from-square text-[8px]"></i>
                            </a>
                        </div>

                        {{-- Photo d'autopsie — object-contain : la preuve visuelle doit
                             être montrée ENTIÈRE (une lésion recadrée hors champ est
                             inutile au diagnostic). Fond sombre = letterboxing propre. --}}
                        @if($incident->photo_path)
                            <div class="h-48 w-full bg-slate-900 relative group overflow-hidden flex items-center justify-center">
                                <img src="{{ media_url($incident->photo_path) }}" alt="Autopsie" loading="lazy" class="max-w-full max-h-full object-contain opacity-90 group-hover:opacity-100 group-hover:scale-105 transition-all duration-500">
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent flex items-end p-4 pointer-events-none">
                                    <span class="text-white text-[9px] uppercase tracking-widest font-black"><i class="fa-solid fa-camera mr-1"></i> {{ __("Preuve visuelle jointe") }}</span>
                                </div>
                            </div>
                        @else
                            <div class="h-20 w-full bg-slate-50 flex items-center justify-center border-b border-slate-100">
                                <p class="text-[9px] text-slate-400 uppercase tracking-widest font-black"><i class="fa-solid fa-image-slash mr-1"></i> {{ __("Aucune photo fournie") }}</p>
                            </div>
                        @endif

                        {{-- Détails de l'incident --}}
                        <div class="p-6 flex-grow flex flex-col gap-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-[9px] uppercase tracking-widest text-slate-400 font-black mb-1">{{ __("Localisation") }}</p>
                                    <h3 class="text-lg font-black text-slate-800 tracking-tighter leading-none">{{ $incident->building->name ?? __("Bâtiment Inconnu") }}</h3>
                                    @if($incident->batch)
                                        <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest mt-1">{{ __("Lot") }} #{{ $incident->batch->code }}</p>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <p class="text-[9px] uppercase tracking-widest text-slate-400 font-black mb-1">{{ __("Mortalité") }}</p>
                                    <h3 class="text-lg font-black text-rose-600 tracking-tighter leading-none">{{ $incident->mortality_count }} <small class="text-[10px]">{{ __("sujets") }}</small></h3>
                                </div>
                            </div>

                            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                                <p class="text-[9px] uppercase tracking-widest text-slate-400 font-black mb-2">{{ __("Symptômes observés par") }} {{ $incident->user->name ?? __("l'agent") }}</p>
                                <p class="text-xs text-slate-700 font-medium leading-relaxed">{{ $incident->symptoms }}</p>
                                @if($incident->daily_check_id)
                                    <p class="text-[8px] uppercase tracking-widest text-blue-400 font-black mt-2"><i class="fa-solid fa-clipboard-list mr-1"></i>{{ __("Issu du pointage du") }} {{ optional($incident->dailyCheck)->check_date?->format('d/m/Y') }}</p>
                                @endif
                            </div>

                            {{-- Bloc Diagnostic Vétérinaire (Si rempli) --}}
                            @if($incident->suspected_disease)
                                <div class="bg-blue-50 p-4 rounded-2xl border border-blue-100 mt-auto">
                                    <p class="text-[9px] uppercase tracking-widest text-blue-400 font-black mb-1"><i class="fa-solid fa-user-doctor mr-1"></i> {{ __("Diagnostic Vétérinaire") }}</p>
                                    <p class="text-xs text-blue-800 font-black uppercase">{{ $incident->suspected_disease }}</p>
                                    @if($incident->vet_prescription)
                                        <p class="text-[10px] text-blue-600 font-medium mt-2 leading-tight">{{ $incident->vet_prescription }}</p>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Action Dynamique selon le Cycle de Vie --}}
                        <div class="p-4 border-t border-slate-100 bg-white mt-auto">
                            
                            {{-- 🔒 Sécurisation des actions de diagnostic et résolution --}}
                            @can('elevage.M')
                                @if($incident->status !== 'resolu')
                                    <div class="flex gap-2">
                                        @if($incident->status === 'en_attente')
                                            <button type="button" x-data @click="$dispatch('open-diagnosis-modal', {{ $incident->id }})" class="flex-1 py-3 bg-slate-900 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-600 transition-colors flex justify-center items-center gap-2 shadow-md">
                                                <i class="fa-solid fa-stethoscope"></i> {{ __("Diagnostic") }}
                                            </button>
                                            <button type="button" x-data @click="$dispatch('open-fast-close-modal', {{ $incident->id }})" title="{{ __("Clôturer sans diagnostic (Cause technique/Erreur)") }}" class="w-12 py-3 bg-slate-100 text-slate-400 rounded-xl text-[10px] hover:bg-slate-200 hover:text-slate-800 transition-colors flex justify-center items-center shadow-sm">
                                                <i class="fa-solid fa-power-off text-sm"></i>
                                            </button>
                                        @elseif($incident->status === 'diagnostique')
                                            <button type="button" x-data @click="$dispatch('open-resolve-modal', {{ $incident->id }})" class="flex-1 py-3 bg-emerald-100 text-emerald-700 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-emerald-500 hover:text-white transition-colors flex justify-center items-center gap-2 shadow-sm border border-emerald-200">
                                                <i class="fa-solid fa-check-double"></i> {{ __("Marquer comme résolu") }}
                                            </button>
                                        @endif

                                        {{-- Quarantaine : isoler / réintégrer le lot --}}
                                        <form action="{{ route('health.incidents.quarantine', $incident->id) }}" method="POST" class="shrink-0">
                                            @csrf @method('PATCH')
                                            <button type="submit" title="{{ $incident->is_quarantined ? __('Lever la quarantaine') : __('Mettre en quarantaine') }}"
                                                @class([
                                                    'w-12 py-3 rounded-xl text-sm flex justify-center items-center shadow-sm transition-colors border-none cursor-pointer',
                                                    'bg-purple-600 text-white hover:bg-purple-700' => $incident->is_quarantined,
                                                    'bg-slate-100 text-slate-400 hover:bg-purple-100 hover:text-purple-600' => ! $incident->is_quarantined,
                                                ])>
                                                <i class="fa-solid fa-shield-virus"></i>
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            @endcan

                            {{-- Affichage du statut clos (visible par tous) --}}
                            @if($incident->status === 'resolu' || $incident->status === 'clos')
                                <div class="w-full py-3 bg-slate-50 text-slate-400 rounded-xl text-[10px] font-black uppercase tracking-widest flex justify-center items-center gap-2 cursor-not-allowed">
                                    <i class="fa-solid fa-lock"></i> {{ __("Dossier Médical Clos") }}
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="col-span-full bg-white p-20 rounded-[3rem] text-center shadow-sm border border-slate-100">
                        <i class="fa-solid fa-shield-virus text-6xl text-emerald-400 mb-6"></i>
                        <h3 class="text-xl font-black uppercase tracking-tighter text-slate-800 mb-2">Cheptel Sécurisé</h3>
                        <p class="text-[10px] uppercase tracking-widest text-slate-400 font-black">Aucune alerte sanitaire ou autopsie en cours.</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-8">
                {{ $incidents->links() }}
            </div>
            
        </div>
    </div>
    {{-- MODALE DE DIAGNOSTIC VÉTÉRINAIRE --}}
    <div x-data="{ showDiagModal: false, incidentId: null, formUrl: '' }" 
         @open-diagnosis-modal.window="incidentId = $event.detail; formUrl = '{{ url('health/incidents') }}/' + incidentId + '/diagnose'; showDiagModal = true;"
         x-show="showDiagModal" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4" 
         style="display: none;"
         x-transition>
        
        <div @click.away="showDiagModal = false" class="bg-white rounded-[3rem] shadow-2xl w-full max-w-lg overflow-hidden text-left border border-slate-100">
            
            <div class="bg-slate-900 p-8 text-white flex justify-between items-center relative overflow-hidden">
                <i class="fa-solid fa-user-doctor absolute -right-4 -top-4 text-[6rem] opacity-10"></i>
                <div class="relative z-10">
                    <h3 class="text-xl font-black uppercase tracking-tighter italic leading-none">Diagnostic Médical</h3>
                    <p class="text-[10px] text-blue-400 font-bold uppercase tracking-widest mt-1">Validation Vétérinaire</p>
                </div>
                <button @click="showDiagModal = false" class="text-white hover:text-blue-400 relative z-10"><i class="fa-solid fa-xmark text-2xl"></i></button>
            </div>

            {{-- Le formUrl est généré dynamiquement par Alpine.js --}}
            <form :action="formUrl" method="POST" class="p-8 space-y-6">
                @csrf
                @method('PUT')
                
                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">Maladie suspectée / Cause *</label>
                    <input type="text" name="suspected_disease" required placeholder="Ex: Coccidiose, Maladie de Gumboro..." class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs text-slate-800 shadow-inner focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">Prescription / Marche à suivre</label>
                    <textarea name="vet_prescription" rows="3" placeholder="Traitement recommandé, isolement, etc." class="w-full bg-slate-50 border-none rounded-xl p-4 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-500 shadow-inner"></textarea>
                </div>

                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">Coût du traitement ({{ currency() }})</label>
                    <input type="number" name="treatment_cost" min="0" step="any" placeholder="0" class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs text-slate-800 shadow-inner focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-blue-600 text-white py-5 rounded-xl font-black uppercase tracking-widest transition-all hover:bg-slate-900 text-[10px] shadow-lg flex items-center justify-center gap-2">
                        <i class="fa-solid fa-file-medical"></i> Enregistrer la prescription
                    </button>
                </div>
            </form>
        </div>
    </div>
    {{-- MODALE DE CLÔTURE RAPIDE (SANS DIAGNOSTIC) --}}
    <div x-data="{ showCloseModal: false, incidentId: null, formUrl: '' }" 
         @open-fast-close-modal.window="incidentId = $event.detail; formUrl = '{{ url('health/incidents') }}/' + incidentId + '/close-fast'; showCloseModal = true;"
         x-show="showCloseModal" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4" 
         style="display: none;"
         x-transition>
        
        <div @click.away="showCloseModal = false" class="bg-white rounded-[3rem] shadow-2xl w-full max-w-lg overflow-hidden text-left border border-slate-100">
            
            <div class="bg-slate-100 p-8 text-slate-800 flex justify-between items-center relative overflow-hidden border-b border-slate-200">
                <i class="fa-solid fa-triangle-exclamation absolute -right-4 -top-4 text-[6rem] text-slate-200 opacity-50"></i>
                <div class="relative z-10">
                    <h3 class="text-xl font-black uppercase tracking-tighter italic leading-none">Clôture Rapide</h3>
                    <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-1">Incident non médical</p>
                </div>
                <button @click="showCloseModal = false" class="text-slate-400 hover:text-slate-800 relative z-10"><i class="fa-solid fa-xmark text-2xl"></i></button>
            </div>

            <form :action="formUrl" method="POST" class="p-8 space-y-6">
                @csrf
                @method('PATCH')
                
                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">Raison technique de la clôture *</label>
                    <textarea name="justification" required rows="3" placeholder="Ex: Accident matériel (étouffement), fausse alerte, prédateur..." class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs text-slate-800 shadow-inner focus:ring-2 focus:ring-slate-500"></textarea>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-slate-800 text-white py-5 rounded-xl font-black uppercase tracking-widest transition-all hover:bg-slate-900 text-[10px] shadow-lg flex items-center justify-center gap-2">
                        <i class="fa-solid fa-power-off"></i> Confirmer la clôture
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODALE DE RÉSOLUTION (note de clôture obligatoire) --}}
    <div x-data="{ showResolveModal: false, incidentId: null, formUrl: '' }"
         @open-resolve-modal.window="incidentId = $event.detail; formUrl = '{{ url('health/incidents') }}/' + incidentId + '/resolve'; showResolveModal = true;"
         x-show="showResolveModal"
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4"
         style="display: none;"
         x-transition>

        <div @click.away="showResolveModal = false" class="bg-white rounded-[3rem] shadow-2xl w-full max-w-lg overflow-hidden text-left border border-slate-100">

            <div class="bg-emerald-600 p-8 text-white flex justify-between items-center relative overflow-hidden">
                <i class="fa-solid fa-check-double absolute -right-4 -top-4 text-[6rem] opacity-10"></i>
                <div class="relative z-10">
                    <h3 class="text-xl font-black uppercase tracking-tighter italic leading-none">Résolution du cas</h3>
                    <p class="text-[10px] text-emerald-200 font-bold uppercase tracking-widest mt-1">Clôture médicale</p>
                </div>
                <button @click="showResolveModal = false" class="text-white hover:text-emerald-200 relative z-10"><i class="fa-solid fa-xmark text-2xl"></i></button>
            </div>

            <form :action="formUrl" method="POST" class="p-8 space-y-6">
                @csrf
                @method('PATCH')

                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">Conclusion / mesures prises *</label>
                    <textarea name="resolution_notes" required rows="3" placeholder="Ex: Traitement terminé, mortalité revenue à la normale, lot réintégré..." class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs text-slate-800 shadow-inner focus:ring-2 focus:ring-emerald-500"></textarea>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-emerald-600 text-white py-5 rounded-xl font-black uppercase tracking-widest transition-all hover:bg-emerald-700 text-[10px] shadow-lg flex items-center justify-center gap-2">
                        <i class="fa-solid fa-check-double"></i> Confirmer la résolution
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>