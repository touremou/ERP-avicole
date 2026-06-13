@php
    // 1. DÉTERMINATION DU THÈME ET DES LABELS
    $consoType = $item->getMeta('conso_type');
    $poultryType = $item->getMeta('poultry_type', 'N/A');
    
    // Fallback pour l'affichage de la catégorie (Gestion de l'ancienne nomenclature)
    $categoryLabel = $item->category === 'matiere_premiere' ? 'materiels' : $item->category;

    $displayType = __("Article");
    $themeKey = 'Aliment';

    if ($categoryLabel === 'oeufs') {
        $displayType = __("Production");
        $themeKey = 'Oeufs';
    } elseif ($categoryLabel === 'materiels') {
        $displayType = __("Équipement");
        $themeKey = 'Materiels';
    } elseif ($categoryLabel === 'litieres') {
        $displayType = __("Litière");
        $themeKey = 'Litieres';
    } else {
        $displayType = $consoType ?? __("Consommable");
        $themeKey = $consoType ?? 'Aliment';
    }

    $themes = [
        'Aliment'    => ['bg' => 'bg-slate-50', 'text' => 'text-slate-600', 'icon' => 'fa-wheat-awn', 'border' => 'border-slate-200'],
        'Santé'      => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600', 'icon' => 'fa-kit-medical', 'border' => 'border-blue-200'],
        'Hygiène'    => ['bg' => 'bg-cyan-50', 'text' => 'text-cyan-600', 'icon' => 'fa-soap', 'border' => 'border-cyan-200'],
        'Oeufs'      => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'icon' => 'fa-egg', 'border' => 'border-emerald-200'],
        'Materiels'  => ['bg' => 'bg-purple-50', 'text' => 'text-purple-600', 'icon' => 'fa-screwdriver-wrench', 'border' => 'border-purple-200'],
        'Litieres'   => ['bg' => 'bg-orange-50', 'text' => 'text-orange-600', 'icon' => 'fa-rug', 'border' => 'border-orange-200'],
    ];
    
    $theme = $themes[$themeKey] ?? $themes['Aliment'];

    // 2. LOGIQUE DE PRÉCISION DES UNITÉS
    // KG et Alvéole demandent de la précision, Sac et Pcs sont des entiers.
    $precision = in_array($item->unit, ['KG', 'Alvéole', 'Litre']) ? 2 : 0;
@endphp

<div @class([
    'bg-white p-6 rounded-[2.5rem] border shadow-sm relative group overflow-hidden transition-all hover:shadow-md',
    'border-red-200 bg-red-50/10' => $item->current_quantity <= $item->alert_threshold,
    'border-slate-100' => $item->current_quantity > $item->alert_threshold
])>
    {{-- Badge de Type --}}
    <div class="absolute top-0 right-10">
        <div class="{{ $theme['bg'] }} {{ $theme['text'] }} px-3 py-1 rounded-b-xl border-x border-b {{ $theme['border'] }} text-[7px] font-black uppercase tracking-widest flex items-center gap-1 shadow-sm">
            <i class="fa-solid {{ $theme['icon'] }} text-[6px]"></i> {{ $displayType }}
        </div>
    </div>

    {{-- Header : Unité & Actions --}}
    <div class="flex justify-between items-start mb-2">
        <span class="text-[9px] font-black text-slate-300 uppercase tracking-widest italic leading-none">
            {{ __("Unité :") }} {{ $item->unit }}
        </span>
        <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-all duration-300">
            <a href="{{ route('stocks.show', $item->id) }}" class="w-7 h-7 rounded-lg bg-slate-50 text-slate-400 flex items-center justify-center hover:bg-slate-900 hover:text-white transition-all shadow-sm">
                <i class="fa-solid fa-eye text-[9px]"></i>
            </a>
            @can('logistique.M')
            <a href="{{ route('stocks.edit', $item->id) }}" class="w-7 h-7 rounded-lg bg-slate-50 text-slate-400 flex items-center justify-center hover:bg-slate-900 hover:text-white transition-all shadow-sm">
                <i class="fa-solid fa-pen text-[9px]"></i>
            </a>
            @endcan
        </div>
    </div>
    
    {{-- Désignation --}}
    <div class="min-h-[50px]">
        <h4 class="text-lg font-black uppercase italic tracking-tighter leading-tight text-slate-800">
            {{ str_replace(['Chair ', 'Ponte '], '', $item->item_name) }}
        </h4>
        @if($item->category === 'conso' && $themeKey === 'Aliment')
            <p class="text-[8px] font-bold text-slate-400 uppercase italic leading-none mt-1">{{ __("Secteur :") }} {{ $poultryType }}</p>
        @elseif($categoryLabel === 'materiels')
             <p class="text-[8px] font-bold text-purple-400 uppercase italic leading-none mt-1">{{ __("Équipement Technique") }}</p>
        @endif
    </div>
    
    {{-- Quantité Centrale --}}
    <div class="flex items-end gap-2 mt-4">
        <p @class([
            'text-4xl font-black leading-none tracking-tighter',
            'text-red-500 animate-pulse' => $item->current_quantity <= $item->alert_threshold,
            'text-slate-900' => $item->current_quantity > $item->alert_threshold
        ])>
            {{ number_format($item->current_quantity, $precision, ',', ' ') }}
        </p>
        
        <div class="flex flex-col mb-1">
            <span class="text-[9px] font-black text-slate-400 uppercase leading-none">{{ $item->unit }}</span>
            
            {{-- Conversion visuelle pour les aliments en KG --}}
            @if($item->category === 'conso' && $themeKey === 'Aliment' && $item->unit === 'KG')
                <span class="text-[8px] font-black text-emerald-500 uppercase italic tracking-tighter mt-1">
                    ≈ {{ number_format($item->current_quantity / 50, 1) }} {{ __("Sacs") }}
                </span>
            @endif
        </div>
    </div>

    {{-- Indicateur Alvéoles (Spécifique) --}}
    @if($item->unit === 'Alvéole' && $item->current_quantity > 0)
        <div class="mt-2 text-[7px] text-blue-500 font-black uppercase italic">
            {{ __("Soit") }} {{ floor($item->current_quantity) }} {{ __("plateaux") }} + {{ round(($item->current_quantity - floor($item->current_quantity)) * 30) }} {{ __("œufs") }}
        </div>
    @endif

    {{-- Barre de Santé du Stock --}}
    <div class="mt-4 h-1 bg-slate-50 rounded-full overflow-hidden">
        @php 
            $maxProgress = $item->alert_threshold > 0 ? $item->alert_threshold * 4 : 100;
            $percentage = $maxProgress > 0 ? ($item->current_quantity / $maxProgress) * 100 : 0;
        @endphp
        <div class="h-full transition-all duration-1000 {{ $item->current_quantity <= $item->alert_threshold ? 'bg-red-500' : 'bg-slate-900' }}" 
             style="width: {{ min($percentage, 100) }}%"></div>
    </div>

    {{-- Footer --}}
    <div class="mt-4 pt-3 border-t border-slate-50 flex justify-between items-center text-[7px] font-black uppercase italic tracking-widest">
        <span class="text-slate-300">{{ __("Cat :") }} {{ strtoupper($categoryLabel) }}</span>
        <span class="text-slate-500">{{ __("Prov :") }} {{ $item->getMeta('supplier', 'Standard') }}</span>
    </div>
</div>