<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Analyse') . ' : ' . $formula->name" :subtitle="__('Code') . ' : ' . $formula->code . ' • ' . strtoupper($formula->target_type)" icon="fa-flask-vial" accent="amber" :back="route('formulas.index')">
            <x-slot name="actions">
                {{-- Permission C : Lancer une production basée sur cette formule --}}
                @can('provenderie.C')
                <a href="{{ route('production.create', ['formula_id' => $formula->id]) }}" class="bg-emerald-500 text-white px-6 py-3 rounded-2xl text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-emerald-200 hover:bg-emerald-600 transition-all no-underline">
                    <i class="fa-solid fa-play mr-2"></i> {{ __("Produire ce lot") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            {{-- COLONNE GAUCHE : COMPOSITION & ACTIONS --}}
            <div class="space-y-6">
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm text-left">
                    <h3 class="text-xs font-black uppercase text-slate-400 mb-6 italic tracking-widest">{{ __("Répartition Ingrédients") }}</h3>
                    <div class="space-y-4">
                        @foreach($formula->items as $item)
                        <div class="flex justify-between items-center p-4 bg-slate-50 rounded-2xl border border-slate-100/50 group hover:bg-white hover:border-blue-200 transition-all">
                            <span class="text-sm font-black text-slate-800 uppercase italic">{{ $item->rawMaterial->name }}</span>
                            <span class="text-lg font-black text-blue-600 italic">{{ number_format($item->percentage, 1) }}%</span>
                        </div>
                        @endforeach
                    </div>

                    {{-- ZONE D'ACTIONS CRITIQUES (SÉCURISÉE) --}}
                    <div class="mt-10 pt-8 border-t border-slate-100 space-y-3">
                        <p class="text-[9px] font-black text-slate-300 uppercase text-center mb-4 italic tracking-widest">{{ __("Administration de la fiche") }}</p>
                        <div class="grid grid-cols-1 gap-3">
                            {{-- Permission M : Édition --}}
                            @can('provenderie.M')
                            <a href="{{ route('formulas.edit', $formula->id) }}" class="flex items-center justify-center gap-2 p-4 bg-slate-900 text-white rounded-2xl hover:bg-blue-600 transition-all text-[10px] uppercase tracking-widest no-underline">
                                <i class="fa-solid fa-pen-to-square text-amber-400"></i> {{ __("Optimiser la Recette") }}
                            </a>
                            @endcan
                            
                            {{-- Permission S : Suppression --}}
                            @can('provenderie.S')
                            <form action="{{ route('formulas.destroy', $formula->id) }}" method="POST" onsubmit="return confirm({{ Js::from(__('Attention : Cette action est irréversible. Supprimer cette formulation ?')) }})">
                                @csrf @method('DELETE')
                                <button type="submit" class="w-full flex items-center justify-center gap-2 p-4 bg-red-50 text-red-500 rounded-2xl hover:bg-red-500 hover:text-white transition-all text-[10px] uppercase tracking-widest border border-red-100 italic font-black">
                                    <i class="fa-solid fa-trash"></i> {{ __("Retirer du Catalogue") }}
                                </button>
                            </form>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>

            {{-- COLONNE DROITE : ANALYSE --}}
            <div class="lg:col-span-2 space-y-8 text-left">
                <div class="bg-slate-900 p-10 rounded-[3.5rem] shadow-2xl text-white relative overflow-hidden group">
                    <div class="absolute right-0 top-0 p-10 opacity-5 group-hover:rotate-12 transition-transform pointer-events-none">
                        <i class="fa-solid fa-chart-pie text-9xl"></i>
                    </div>

                    <div class="flex flex-wrap justify-between items-baseline gap-3 mb-8 relative">
                        <h3 class="text-xs font-black uppercase text-blue-400 italic tracking-widest leading-none">{{ __("Équilibre Nutritionnel vs Norme") }}</h3>
                        @if($norm)
                            <span class="text-[9px] font-black uppercase italic text-white/60">
                                {{ $norm->name }} · {{ $norm->phase }}
                            </span>
                        @endif
                    </div>

                    @unless($norm)
                        {{-- Auparavant, la fiche affichait ici 3 000 kcal / 20 % / 1,1 %
                             sous l'étiquette « Cible (Norme) » alors qu'aucune norme
                             n'était rattachée : une cible inventée a l'autorité d'une
                             cible mesurée. --}}
                        <div class="relative mb-6 p-5 rounded-[2rem] bg-amber-500/10 border border-amber-400/30">
                            <p class="text-[10px] font-black uppercase italic text-amber-300 leading-tight">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                {{ __("Aucune norme du référentiel ne correspond au type") }} « {{ $formula->target_type }} »
                            </p>
                            <p class="text-[9px] font-bold italic text-white/50 mt-2 leading-snug">
                                {{ __("Les teneurs ci-dessous sont celles du mélange ; aucune cible ne leur est opposée. Ajoutez la norme au référentiel pour obtenir un verdict.") }}
                            </p>
                        </div>
                    @endunless

                    @if($candidates->count() > 1)
                        <div class="relative mb-6 p-5 rounded-[2rem] bg-blue-500/10 border border-blue-400/30">
                            <p class="text-[10px] font-black uppercase italic text-blue-300 leading-tight">
                                <i class="fa-solid fa-circle-info mr-1"></i>
                                {{ __("Le référentiel propose :count normes pour ce type", ['count' => $candidates->count()]) }}
                            </p>
                            <p class="text-[9px] font-bold italic text-white/50 mt-2 leading-snug">
                                {{ $candidates->pluck('phase')->implode(' · ') }} —
                                {{ __("la phase retenue est la première du référentiel. Précisez la phase visée sur la norme ou sur la formule.") }}
                            </p>
                        </div>
                    @endif

                    <div class="relative">
                        @include('provenderie.formulas.partials.nutrient-bars', [
                            'comparison' => $comparison,
                            'dark' => true,
                        ])
                    </div>
                </div>

                {{-- RÉSUMÉ FINANCIER --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm group hover:border-blue-200 transition-all text-left">
                        <p class="text-[9px] font-black text-slate-400 uppercase italic mb-1 leading-none">{{ __("Coût de Revient Théorique") }}</p>
                        <p class="text-4xl font-black text-slate-900 italic tracking-tighter leading-none">
                            {{ number_format($verdict['cost'], 0, ',', ' ') }}
                            <small class="text-xs opacity-30 italic">{{ currency() }}/kg</small>
                        </p>
                        @if($verdict['target'])
                            <p class="text-[9px] font-black uppercase italic text-slate-400 mt-3 leading-none">
                                {{ __("Prix cible au référentiel") }} :
                                {{ number_format($verdict['target'], 0, ',', ' ') }} {{ currency() }}/kg
                            </p>
                        @endif
                    </div>

                    {{-- VERDICT ÉCONOMIQUE. Cet écran tranchait sur « coût < 5 000 »
                         codé en dur : un aliment d'alevinage (cible 9 500/kg) était
                         déclaré « À RÉVISER » alors que la liste, elle, le donnait
                         sous la norme — deux écrans, deux verdicts opposés. --}}
                    <div @class([
                        'p-8 rounded-[3rem] shadow-sm relative overflow-hidden text-left border',
                        'bg-slate-50 border-slate-100'      => $verdict['status'] === 'unknown',
                        'bg-emerald-50 border-emerald-100'  => $verdict['status'] === 'under',
                        'bg-slate-50 border-slate-200'      => $verdict['status'] === 'near',
                        'bg-red-50 border-red-100'          => $verdict['status'] === 'over',
                    ])>
                        <div class="absolute right-0 bottom-0 p-4 opacity-10"><i class="fa-solid fa-piggy-bank text-4xl"></i></div>
                        <p class="text-[9px] font-black uppercase italic mb-1 leading-none text-slate-500">{{ __("Performance Économique") }}</p>
                        <p @class([
                            'text-3xl font-black italic tracking-tighter leading-none',
                            'text-slate-400'   => $verdict['status'] === 'unknown',
                            'text-emerald-600' => $verdict['status'] === 'under',
                            'text-slate-700'   => $verdict['status'] === 'near',
                            'text-red-500'     => $verdict['status'] === 'over',
                        ])>{{ $verdict['label'] }}</p>
                        @if($verdict['diff'] !== null)
                            <p class="text-[10px] font-black uppercase italic text-slate-400 mt-2 leading-none">
                                {{ $verdict['diff'] <= 0 ? '−' : '+' }}{{ number_format(abs($verdict['diff']), 0, ',', ' ') }} {{ currency() }}/kg
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>