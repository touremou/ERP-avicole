<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div class="w-14 h-14 bg-orange-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                    <i class="fa-solid fa-boxes-stacked text-xl"></i>
                </div>
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">Inventaire Global</h2>
                    <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mt-2 italic">
                        Gestion des stocks multi-catégories
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                @can('stocks.M')
                <a href="{{ route('stocks.export', ['category' => request('category', 'oeufs')]) }}" class="bg-white border border-slate-200 px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest text-slate-600 hover:bg-slate-50 transition-all no-underline flex items-center gap-2">
                    <i class="fa-solid fa-file-excel text-emerald-500"></i> Export
                </a>
                @endcan
                @can('stocks.C')
                <a href="{{ route('stocks.create') }}" class="bg-slate-900 text-white px-8 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-orange-600 transition-all shadow-xl italic no-underline flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nouvel Article
                </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10" x-data="{ activeTab: '{{ request('category', 'oeufs') }}' }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-8 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success',
                        'bg-red-500 text-white' => $msg === 'error',
                    ])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i>
                        {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- ONGLETS CATÉGORIES --}}
            @php
                $categories = [
                    'oeufs'          => ['label' => 'Œufs',           'icon' => 'fa-egg',                'color' => 'amber'],
                    'conso'          => ['label' => 'Aliment & Santé','icon' => 'fa-wheat-awn',          'color' => 'emerald'],
                    'produits_finis' => ['label' => 'Produits Finis', 'icon' => 'fa-drumstick-bite',     'color' => 'rose'],
                    'litieres'       => ['label' => 'Litières',       'icon' => 'fa-leaf',               'color' => 'purple'],
                    'materiels'      => ['label' => 'Matériel',       'icon' => 'fa-screwdriver-wrench', 'color' => 'blue'],
                ];
            @endphp

            <div class="flex gap-3 mb-8 overflow-x-auto pb-2">
                @foreach($categories as $catKey => $cat)
                    <a href="{{ route('stocks.index', ['category' => $catKey]) }}"
                       @click="activeTab = '{{ $catKey }}'"
                       @class([
                           'px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest transition-all no-underline flex items-center gap-3 shrink-0',
                           "bg-{$cat['color']}-500 text-white shadow-lg" => request('category', 'oeufs') === $catKey,
                           "bg-white text-slate-500 border border-slate-100 hover:bg-{$cat['color']}-50 hover:text-{$cat['color']}-600" => request('category', 'oeufs') !== $catKey,
                       ])>
                        <i class="fa-solid {{ $cat['icon'] }}"></i>
                        {{ $cat['label'] }}
                        @php $catCount = \App\Models\Stock::where('category', $catKey)->count(); @endphp
                        <span @class([
                            'text-[8px] px-2 py-0.5 rounded-full font-black',
                            'bg-white/20' => request('category', 'oeufs') === $catKey,
                            'bg-slate-100' => request('category', 'oeufs') !== $catKey,
                        ])>{{ $catCount }}</span>
                    </a>
                @endforeach
            </div>

            {{-- LOGIQUE DE SOUS-CATÉGORIES --}}
            @php
                $currentCategory = request('category', 'oeufs');
                $sections = [];

                if ($currentCategory === 'conso') {
                    // Découpage intelligent si on est dans les consommables
                    $sections = [
                        [
                            'title' => 'Alimentation Chair',
                            'icon' => 'fa-feather-pointed',
                            'text_color' => 'text-slate-800',
                            'border_color' => 'border-slate-800',
                            'items' => $stocks->filter(fn($s) => ($s->metadata['poultry_type'] ?? '') === 'Chair' && ($s->metadata['conso_type'] ?? '') === 'Aliment')
                        ],
                        [
                            'title' => 'Alimentation Ponte & Repro',
                            'icon' => 'fa-egg',
                            'text_color' => 'text-emerald-600',
                            'border_color' => 'border-emerald-500',
                            'items' => $stocks->filter(fn($s) => in_array($s->metadata['poultry_type'] ?? '', ['Ponte', 'Reproducteur']) && ($s->metadata['conso_type'] ?? '') === 'Aliment')
                        ],
                        [
                            'title' => 'Santé & Pharmacie',
                            'icon' => 'fa-kit-medical',
                            'text_color' => 'text-blue-600',
                            'border_color' => 'border-blue-500',
                            'items' => $stocks->filter(fn($s) => ($s->metadata['conso_type'] ?? '') === 'Santé')
                        ],
                        [
                            'title' => 'Hygiène & Entretien',
                            'icon' => 'fa-soap',
                            'text_color' => 'text-cyan-600',
                            'border_color' => 'border-cyan-500',
                            'items' => $stocks->filter(fn($s) => ($s->metadata['conso_type'] ?? '') === 'Hygiène')
                        ],
                        [
                            'title' => 'Autres Consommables',
                            'icon' => 'fa-box-open',
                            'text_color' => 'text-slate-500',
                            'border_color' => 'border-slate-300',
                            'items' => $stocks->filter(fn($s) => !in_array($s->metadata['conso_type'] ?? '', ['Aliment', 'Santé', 'Hygiène']))
                        ]
                    ];
                } else {
                    // Un seul grand tableau pour les autres catégories (Œufs, Produits Finis, Litières, Matériels)
                    $sections = [
                        [
                            'title' => null,
                            'items' => $stocks
                        ]
                    ];
                }
            @endphp

            {{-- AFFICHAGE DES TABLEAUX --}}
            @foreach($sections as $section)
                @if($section['items']->count() > 0 || $currentCategory !== 'conso')
                    
                    {{-- Titre de la sous-catégorie (Uniquement pour conso) --}}
                    @if($section['title'])
                        <div class="flex items-center gap-3 px-6 mb-4 mt-10 first:mt-0 border-l-4 {{ $section['border_color'] }}">
                            <i class="fa-solid {{ $section['icon'] }} {{ $section['text_color'] }} text-xl"></i>
                            <h3 class="text-sm font-black uppercase italic tracking-widest {{ $section['text_color'] }}">{{ $section['title'] }}</h3>
                        </div>
                    @endif

                    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden mb-6">
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                                        <th class="px-6 py-5 text-left">Article</th>
                                        <th class="px-4 py-5 text-center">Unité</th>
                                        <th class="px-4 py-5 text-right">Quantité</th>
                                        <th class="px-4 py-5 text-right">Seuil Alerte</th>
                                        <th class="px-4 py-5 text-center">État</th>
                                        <th class="px-4 py-5 text-right">Dernier P.U.</th>
                                        <th class="px-6 py-5 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    @forelse($section['items'] as $stock)
                                    @php
                                        $isLow = $stock->alert_threshold > 0 && $stock->current_quantity <= $stock->alert_threshold;
                                        $isEmpty = $stock->current_quantity <= 0;
                                    @endphp
                                    <tr @class(['hover:bg-slate-50/50 transition-all', 'bg-red-50/30' => $isEmpty, 'bg-amber-50/30' => $isLow && !$isEmpty])>
                                        <td class="px-6 py-4">
                                            <a href="{{ route('stocks.show', $stock->id) }}" class="no-underline group">
                                                <p class="text-sm font-black text-slate-900 uppercase italic group-hover:text-orange-600 transition-colors leading-none mb-1">{{ $stock->item_name }}</p>
                                                <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest">{{ $stock->feed_type ?? $stock->category }}</p>
                                            </a>
                                        </td>
                                        <td class="px-4 py-4 text-center text-[10px] font-black text-slate-500 uppercase">{{ $stock->unit }}</td>
                                        <td class="px-4 py-4 text-right">
                                            <span @class([
                                                'text-lg font-black',
                                                'text-red-600' => $isEmpty,
                                                'text-amber-600' => $isLow && !$isEmpty,
                                                'text-slate-900' => !$isLow && !$isEmpty,
                                            ])>{{ number_format($stock->current_quantity, 1, ',', ' ') }}</span>
                                        </td>
                                        <td class="px-4 py-4 text-right text-[10px] font-black text-slate-400">
                                            {{ $stock->alert_threshold > 0 ? number_format($stock->alert_threshold, 0, ',', ' ') : '—' }}
                                        </td>
                                        <td class="px-4 py-4 text-center">
                                            @if($isEmpty)
                                                <span class="text-[8px] font-black uppercase px-3 py-1 rounded-full bg-red-100 text-red-600 animate-pulse">Rupture</span>
                                            @elseif($isLow)
                                                <span class="text-[8px] font-black uppercase px-3 py-1 rounded-full bg-amber-100 text-amber-600">Bas</span>
                                            @else
                                                <span class="text-[8px] font-black uppercase px-3 py-1 rounded-full bg-emerald-50 text-emerald-600">OK</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-4 text-right text-[10px] font-black text-slate-500">
                                            {{ $stock->unit_price ? number_format($stock->unit_price, 0, ',', ' ') : '—' }}
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex gap-2 justify-end">
                                                <a href="{{ route('stocks.show', $stock->id) }}" class="text-slate-400 hover:text-orange-600 no-underline" title="Détails & mouvements">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                @can('stocks.M')
                                                <a href="{{ route('stocks.edit', $stock->id) }}" class="text-slate-400 hover:text-blue-600 no-underline" title="Modifier">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                @endcan
                                            </div>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="7" class="px-8 py-16 text-center">
                                            <i class="fa-solid fa-box-open text-slate-200 text-3xl mb-4 block"></i>
                                            <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">Aucun article dans cette section</p>
                                            @can('stocks.C')
                                            <a href="{{ route('stocks.create') }}" class="inline-block mt-4 px-6 py-3 bg-slate-900 text-white rounded-2xl text-[9px] font-black uppercase tracking-widest no-underline hover:bg-orange-600 transition-all">
                                                <i class="fa-solid fa-plus mr-1"></i> Créer un article
                                            </a>
                                            @endcan
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- MOUVEMENT RAPIDE --}}
            @can('stocks.M')
            <div class="mt-8 bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm" x-data="{ showMove: false }">
                <button @click="showMove = !showMove" class="w-full flex justify-between items-center border-none bg-transparent cursor-pointer outline-none">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                        <i class="fa-solid fa-arrows-rotate text-blue-500"></i> Mouvement rapide (Entrée / Sortie / Ajustement)
                    </h3>
                    <i class="fa-solid fa-chevron-down text-slate-300 transition-transform" :class="showMove && 'rotate-180'"></i>
                </button>

                <div x-show="showMove" x-transition class="mt-6">
                    <form method="POST" action="{{ route('stocks.move') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                        @csrf
                        <div class="space-y-1">
                            <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Article</label>
                            <select name="stock_id" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-inner outline-none">
                                <option value="">Sélectionner...</option>
                                @foreach($stocks ?? [] as $s)
                                    <option value="{{ $s->id }}">{{ $s->item_name }} ({{ $s->current_quantity }} {{ $s->unit }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="space-y-1">
                            <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Type</label>
                            <select name="type" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-inner outline-none">
                                <option value="in">Entrée</option>
                                <option value="out">Sortie</option>
                                <option value="adjustment">Ajustement</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Quantité</label>
                            <input type="number" name="quantity" step="0.01" min="0.01" required
                                class="w-full bg-slate-50 border-none rounded-xl p-3 text-sm font-black shadow-inner outline-none text-center">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">Motif</label>
                            <input type="text" name="notes" placeholder="Raison du mouvement..."
                                class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] font-bold shadow-inner outline-none">
                        </div>
                        <button type="submit" class="bg-blue-500 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-blue-600 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-check mr-1"></i> Valider
                        </button>
                    </form>
                </div>
            </div>
            @endcan
        </div>
    </div>
</x-app-layout>