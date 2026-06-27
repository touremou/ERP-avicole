<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-4 text-left">
                <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-lg rotate-3">
                    <i class="fa-solid fa-flask-vial text-lg"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">
                        {{ __("Analyse") }} : {{ $formula->name }}
                    </h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Code") }} : {{ $formula->code }} • {{ strtoupper($formula->target_type) }}</p>
                </div>
            </div>
            
            <div class="flex gap-3">
                <x-back />
                
                {{-- Permission C : Lancer une production basée sur cette formule --}}
                @can('provenderie.C')
                <a href="{{ route('production.create', ['formula_id' => $formula->id]) }}" class="bg-emerald-500 text-white px-6 py-3 rounded-2xl text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-emerald-200 hover:bg-emerald-600 transition-all no-underline">
                    <i class="fa-solid fa-play mr-2"></i> {{ __("Produire ce lot") }}
                </a>
                @endcan
            </div>
        </div>
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
                    <h3 class="text-xs font-black uppercase text-blue-400 mb-8 italic tracking-widest relative leading-none">{{ __("Équilibre Nutritionnel vs Norme") }}</h3>
                    
                    <div class="h-64 relative">
                        <canvas id="nutritionChart"></canvas>
                    </div>
                </div>

                {{-- RÉSUMÉ FINANCIER --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm group hover:border-blue-200 transition-all text-left">
                        <p class="text-[9px] font-black text-slate-400 uppercase italic mb-1 leading-none">{{ __("Coût de Revient Théorique") }}</p>
                        <p class="text-4xl font-black text-slate-900 italic tracking-tighter leading-none">
                            {{ number_format($stats['cost'], 0, ',', ' ') }} 
                            <small class="text-xs opacity-30 italic">{{ currency() }}/kg</small>
                        </p>
                    </div>
                    <div class="bg-emerald-50 p-8 rounded-[3rem] border border-emerald-100 shadow-sm relative overflow-hidden text-left">
                        <div class="absolute right-0 bottom-0 p-4 opacity-10"><i class="fa-solid fa-piggy-bank text-4xl"></i></div>
                        <p class="text-[9px] font-black text-emerald-600 uppercase italic mb-1 leading-none">{{ __("Performance Économique") }}</p>
                        <p class="text-4xl font-black text-emerald-600 italic tracking-tighter leading-none">
                            {{ $stats['cost'] < 5000 ? __('Optimum') : __('À Réviser') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('nutritionChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: {!! json_encode($chartData['labels']) !!},
                datasets: [
                    {
                        label: {{ Js::from(__('Réel (Formule)')) }},
                        data: {!! json_encode($chartData['real']) !!},
                        backgroundColor: '#3b82f6',
                        borderRadius: 12,
                    },
                    {
                        label: {{ Js::from(__('Cible (Norme)')) }},
                        data: {!! json_encode($chartData['target']) !!},
                        backgroundColor: 'rgba(255, 255, 255, 0.1)',
                        borderColor: 'white',
                        borderWidth: 2,
                        borderRadius: 12,
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { 
                        display: true, 
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: 'rgba(255,255,255,0.3)', font: { size: 9 } }
                    },
                    x: { 
                        ticks: { color: 'rgba(255,255,255,0.5)', font: { weight: 'bold', size: 10, family: 'italic' } }, 
                        grid: { display: false } 
                    }
                },
                plugins: {
                    legend: { 
                        display: true,
                        labels: { color: 'white', font: { size: 10, weight: 'bold' }, usePointStyle: true }
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleFont: { size: 12, weight: 'bold' },
                        padding: 12,
                        cornerRadius: 12
                    }
                }
            }
        });
    </script>
</x-app-layout>