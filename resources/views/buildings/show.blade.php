<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-4 text-left">
                <a href="{{ route('buildings.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm group no-underline">
                    <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform"></i>
                    <span class="text-[10px] font-black uppercase italic tracking-widest">{{ __("Retour") }}</span>
                </a>

                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none m-0">
                    {{ __('Unité de Production :') }} {{ $building->name }}
                </h2>
            </div>

            <div class="flex items-center gap-3">
                @can('elevage.M')
                <a href="{{ route('buildings.edit', $building->id) }}" class="bg-white border border-slate-200 px-6 py-2.5 rounded-xl text-[10px] font-black uppercase text-slate-600 hover:bg-blue-600 hover:text-white transition shadow-sm tracking-widest italic no-underline">
                    <i class="fas fa-tools mr-2"></i> {{ __("Configurer") }}
                </a>
                @endcan

                @can('elevage.S')
                    @php $isLocked = $building->batches->where('status', 'Actif')->count() > 0; @endphp

                    @if($isLocked)
                        <div class="group relative inline-block">
                            <button type="button" class="cursor-not-allowed opacity-50 bg-slate-100 text-slate-400 border border-slate-200 px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest italic outline-none">
                                <i class="fas fa-lock mr-2"></i> {{ __("Supprimer") }}
                            </button>
                            <div class="absolute bottom-full right-0 mb-2 hidden group-hover:block w-48 p-3 bg-slate-900 text-white text-[8px] font-bold uppercase rounded-xl text-center shadow-xl z-50 italic tracking-tighter leading-tight">
                                <i class="fas fa-info-circle text-orange-400 mr-1"></i>
                                {{ __("Action impossible : ce bâtiment contient un lot actif.") }}
                            </div>
                        </div>
                    @else
                        <form action="{{ route('buildings.destroy', $building->id) }}" method="POST" onsubmit="return confirm({{ Js::from(__("Confirmer l'archivage ? L'historique sera conservé dans la corbeille.")) }});" class="m-0">
                            @csrf @method('DELETE')
                            <button type="submit" class="bg-red-50 text-red-600 border border-red-100 px-6 py-2.5 rounded-xl text-[10px] font-black uppercase hover:bg-red-600 hover:text-white transition tracking-widest italic shadow-sm cursor-pointer">
                                <i class="fas fa-archive mr-2"></i> {{ __("Supprimer") }}
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @php
                $activeBatches = $building->batches->where('status', 'Actif');
                $occupation = $activeBatches->sum('current_quantity');
                $reste = max(0, $building->capacity - $occupation);
                $percent = ($building->capacity > 0) ? ($occupation / $building->capacity) * 100 : 0;

                $totalInitial = $activeBatches->sum('initial_quantity');
                $totalMorts = $activeBatches->sum(function($batch) {
                    return ($batch->qty_dead ?? 0) + $batch->dailyChecks->sum('mortality');
                });
                $tauxMortalite = ($totalInitial > 0) ? ($totalMorts / $totalInitial) * 100 : 0;

                $statusLabel = __("Repos / Disponible");
                $statusClass = "from-emerald-500 to-emerald-600 shadow-emerald-500/20";
                $statusIcon = "fa-check-circle";
                $showCountdown = false;

                if($occupation > 0 || $building->status === 'Occupé') {
                    $statusLabel = __("Occupé");
                    $statusClass = "from-blue-600 to-blue-700 shadow-blue-500/20";
                    $statusIcon = "fa-house-user";
                    if($tauxMortalite >= 5) {
                        $statusLabel = __("Alerte Sanitaire");
                        $statusClass = "from-red-500 to-red-600 shadow-red-500/20";
                        $statusIcon = "fa-biohazard";
                    }
                } elseif($building->status === 'En désinfection' || ($occupation == 0 && $building->status !== 'Vide')) {
                    $statusLabel = __("Vide Sanitaire");
                    $statusClass = "from-purple-600 to-indigo-700 shadow-purple-500/20 animate-pulse";
                    $statusIcon = "fa-biohazard";
                    $showCountdown = true;
                    $hoursSinceUpdate = $building->updated_at->diffInHours(now());
                    $daysRemainingDisplay = ceil(max(0, 14 - ($hoursSinceUpdate / 24)));
                } else {
                    $statusLabel = __("Prêt / Repos");
                    $statusClass = "from-emerald-500 to-emerald-600 shadow-emerald-500/20";
                    $statusIcon = "fa-check-circle";
                }
            @endphp

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- COLONNE GAUCHE --}}
                <div class="space-y-6">
                    <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 text-center">
                        <div class="w-20 h-20 mx-auto mb-6 rounded-3xl bg-slate-900 flex items-center justify-center text-yellow-500 text-3xl shadow-lg shadow-slate-900/20">
                            <i class="fas fa-warehouse"></i>
                        </div>
                        <h2 class="text-2xl font-black text-slate-800 leading-tight uppercase tracking-tighter">{{ $building->name }}</h2>
                        <p class="text-blue-600 font-black uppercase text-[10px] tracking-[0.2em] mt-2 italic border-b border-slate-50 pb-4 inline-block">{{ $building->type }}</p>

                        <div class="mt-4 space-y-3 text-left font-bold">
                            <div class="flex justify-between items-center p-4 bg-slate-50 rounded-2xl shadow-inner">
                                <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Surface utile") }}</span>
                                <span class="text-slate-700">{{ $building->surface }} m²</span>
                            </div>
                            <div class="flex justify-between items-center p-4 bg-slate-50 rounded-2xl shadow-inner">
                                <span class="text-[9px] font-black uppercase text-slate-400 tracking-widest italic">{{ __("Densité Max") }}</span>
                                <span class="text-slate-700">{{ round($building->capacity / ($building->surface ?: 1), 1) }} {{ __("suj/m²") }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="p-8 rounded-[2.5rem] shadow-xl text-white flex items-center justify-between bg-gradient-to-br {{ $statusClass }} transition-all duration-500 relative overflow-hidden text-left">
                        <div class="relative z-10">
                            <p class="text-[9px] font-black uppercase opacity-70 mb-1 tracking-widest italic">{{ __("État Sanitaire") }}</p>
                            <p class="text-3xl font-black italic tracking-tighter uppercase leading-none">{{ $statusLabel }}</p>
                            @if($showCountdown)
                                <div class="mt-4 inline-block px-3 py-1 bg-white/20 rounded-lg backdrop-blur-md border border-white/10">
                                    <p class="text-[9px] font-black uppercase italic">{{ __("Réouverture dans :") }} <span class="text-yellow-300">{{ $daysRemainingDisplay }} {{ __("jours") }}</span></p>
                                </div>
                            @elseif($occupation > 0)
                                <p class="text-[10px] font-black opacity-80 mt-2 uppercase italic">{{ __("Mortalité cumulée :") }} {{ number_format($tauxMortalite, 1) }}%</p>
                            @endif
                        </div>
                        <i class="fas {{ $statusIcon }} text-5xl opacity-20 absolute -right-2 -bottom-2"></i>
                    </div>

                    <div class="bg-slate-900 p-8 rounded-[2.5rem] text-white shadow-xl relative overflow-hidden text-left">
                        <div class="relative z-10">
                            <p class="text-[9px] font-black text-slate-500 uppercase italic mb-4 tracking-widest">{{ __("Capacité d'accueil") }}</p>
                            <div class="flex items-baseline gap-2 mb-2">
                                <span class="text-4xl font-black tracking-tighter">{{ number_format($occupation) }}</span>
                                <span class="text-slate-500 text-xs">/ {{ number_format($building->capacity) }} {{ __("têtes") }}</span>
                            </div>
                            <div class="w-full h-2 bg-white/10 rounded-full mb-4 overflow-hidden shadow-inner">
                                <div class="h-full bg-blue-500 transition-all duration-1000 shadow-[0_0_15px_rgba(59,130,246,0.5)]" style="width: {{ min($percent, 100) }}%"></div>
                            </div>
                            <div class="flex justify-between text-[10px] font-black uppercase italic">
                                <span class="{{ $percent > 90 ? 'text-red-400' : 'text-blue-400' }}">{{ number_format($percent, 1) }}% {{ __("de charge") }}</span>
                                <span class="text-emerald-400">{{ number_format($reste) }} {{ __("places libres") }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- COLONNE DROITE --}}
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100 min-h-[500px]">
                        <div class="flex justify-between items-center mb-8">
                            <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-[0.2em] flex items-center italic m-0">
                                <span class="w-8 h-[2px] bg-blue-500 mr-3"></span> {{ __("Registre Historique") }}
                            </h3>

                            @can('elevage.C')
                                @if($building->status === 'Vide')
                                    <a href="{{ route('batches.create', ['building_id' => $building->id]) }}" class="text-[8px] font-black uppercase text-blue-600 hover:text-blue-700 bg-blue-50 px-4 py-2 rounded-xl transition no-underline">
                                        {{ __("Lancer un nouveau lot") }}
                                    </a>
                                @endif
                            @endcan
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="text-[9px] font-black uppercase text-slate-400 border-b border-slate-50 tracking-widest italic">
                                        <th class="pb-4">{{ __("Code Lot") }}</th>
                                        <th class="pb-4 text-center">{{ __("Mise en place") }}</th>
                                        <th class="pb-4 text-center">{{ __("Effectif") }}</th>
                                        <th class="pb-4 text-center">{{ __("Mortalité (%)") }}</th>
                                        <th class="pb-4 text-right">{{ __("Statut") }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50 text-left">
                                    @forelse($building->batches->sortByDesc('arrival_date') as $batch)
                                        @php $batchMortality = ($batch->initial_quantity > 0) ? (($batch->qty_dead + $batch->dailyChecks->sum('mortality')) / $batch->initial_quantity) * 100 : 0; @endphp
                                        <tr class="group hover:bg-slate-50 transition-colors cursor-pointer" onclick="window.location='{{ route('batches.show', $batch->id) }}'">
                                            <td class="py-5">
                                                <p class="font-black text-slate-800 uppercase tracking-tighter group-hover:text-blue-600 m-0">{{ $batch->code }}</p>
                                                <p class="text-[8px] text-slate-400 uppercase italic m-0 mt-1">{{ $batch->type }}</p>
                                            </td>
                                            <td class="py-5 text-[10px] font-bold text-slate-600 text-center">{{ Carbon\Carbon::parse($batch->arrival_date)->format('d/m/Y') }}</td>
                                            <td class="py-5 text-center font-black text-slate-700 tracking-tighter">{{ number_format($batch->initial_quantity) }}</td>
                                            <td class="py-5 text-center">
                                                <span class="text-[10px] font-black {{ $batchMortality > 5 ? 'text-red-500' : 'text-slate-500' }}">{{ number_format($batchMortality, 1) }}%</span>
                                            </td>
                                            <td class="py-5 text-right">
                                                <span @class([
                                                    'px-3 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest inline-block',
                                                    'bg-blue-600 text-white shadow-lg shadow-blue-500/20' => $batch->status == 'Actif',
                                                    'bg-slate-100 text-slate-500' => $batch->status != 'Actif'
                                                ])>{{ $batch->status }}</span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="py-24 text-center opacity-10">
                                                <i class="fas fa-history text-6xl mb-4"></i>
                                                <p class="text-[10px] font-black uppercase tracking-[0.3em] m-0">{{ __("Aucun antécédent") }}</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-slate-50 p-8 rounded-[2.5rem] border border-slate-100 flex items-center gap-6 text-left">
                        <div class="w-14 h-14 min-w-[56px] rounded-2xl bg-white shadow-sm flex items-center justify-center text-blue-500 text-xl">
                            <i class="fas fa-hand-sparkles"></i>
                        </div>
                        <div>
                            <h4 class="text-[10px] font-black uppercase text-slate-800 tracking-widest mb-1 italic">{{ __("Dernière désinfection") }}</h4>
                            <p class="text-xs font-bold text-slate-500 italic m-0">
                                {{ $building->updated_at->format('d/m/Y') }} — {{ __("État certifié conforme pour l'entrée d'un nouveau lot après vide sanitaire de 14 jours.") }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>