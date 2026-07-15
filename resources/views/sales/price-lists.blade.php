<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Groupes de prix')" :subtitle="__('Tarifs détail / grossiste par type de produit')" icon="fa-tags" accent="teal" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 text-left space-y-6">

            <x-flash />

            {{-- Création d'un tarif --}}
            <form action="{{ route('sales.price-lists.store') }}" method="POST" class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6 flex flex-wrap items-end gap-4">
                @csrf
                <div class="flex-1 min-w-[200px]">
                    <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Nouveau tarif') }}</label>
                    <input type="text" name="name" required placeholder="{{ __('Ex: Grossiste') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xs uppercase shadow-inner outline-none italic">
                </div>
                <label class="flex items-center gap-2 text-[11px] font-black text-slate-600 uppercase tracking-widest cursor-pointer pb-4">
                    <input type="checkbox" name="is_default" value="1" class="rounded accent-teal-600"> {{ __('Par défaut') }}
                </label>
                <button type="submit" class="bg-slate-900 text-white px-6 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-teal-600 transition-all"><i class="fa-solid fa-plus mr-1"></i>{{ __('Créer') }}</button>
            </form>

            {{-- Édition des prix par tarif --}}
            @forelse($priceLists as $list)
                @php
                    $current = $list->items->whereNull('product_id')->keyBy('product_type');
                    $articleCurrent = $list->items->whereNotNull('product_id')->keyBy('product_id');
                @endphp
                <form action="{{ route('sales.price-lists.update', $list->id) }}" method="POST" class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6">
                    @csrf @method('PUT')
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-black text-sm text-slate-800 uppercase italic">{{ $list->name }}
                            @if($list->is_default)<span class="ml-2 text-[8px] bg-teal-50 text-teal-600 px-2 py-1 rounded-lg">{{ __('Défaut') }}</span>@endif
                        </h3>
                    </div>
                    {{-- PRIX PAR ARTICLE (tarification principale, sur le catalogue réel) --}}
                    @if($products->isNotEmpty())
                    <p class="text-[9px] font-black text-teal-600 uppercase tracking-widest mb-2 italic"><i class="fa-solid fa-box-open mr-1"></i>{{ __('Prix par article') }} <span class="text-slate-300 normal-case">({{ __('vide = prix de base de l\'article') }})</span></p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($products as $product)
                        <div class="flex items-center justify-between gap-3 p-3 rounded-xl bg-teal-50/40">
                            <span class="text-[11px] font-black text-slate-600 italic truncate">{{ $product->name }}</span>
                            <input type="number" min="0" step="1" name="article_prices[{{ $product->id }}]" value="{{ optional($articleCurrent->get($product->id))->unit_price ? (int) $articleCurrent->get($product->id)->unit_price : '' }}"
                                   placeholder="{{ (int) $product->base_price }}" class="w-32 bg-white border-none rounded-lg p-2 text-right font-black text-xs shadow-inner outline-none">
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="p-4 rounded-xl bg-amber-50 border border-amber-100 text-[10px] font-bold text-amber-700 italic mb-2">
                        <i class="fa-solid fa-circle-info mr-1"></i>{{ __('Aucun article au catalogue. Créez des articles pour fixer leurs prix précis ;') }}
                        <a href="{{ route('products.index') }}" class="underline">{{ __('ouvrir le catalogue') }}</a>.
                    </div>
                    @endif

                    {{-- TARIF DE REPLI PAR CATÉGORIE (ventes en saisie libre, hors catalogue) --}}
                    <details class="mt-6 group" {{ $current->isNotEmpty() ? 'open' : '' }}>
                        <summary class="text-[9px] font-black text-slate-400 uppercase tracking-widest italic cursor-pointer select-none">
                            <i class="fa-solid fa-layer-group mr-1"></i>{{ __('Tarif de repli par catégorie') }}
                            <span class="text-slate-300 normal-case">({{ __('appliqué aux ventes en saisie libre, sans article du catalogue') }})</span>
                        </summary>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                            @foreach($productTypes as $type => $label)
                            <div class="flex items-center justify-between gap-3 p-3 rounded-xl bg-slate-50">
                                <span class="text-[11px] font-black text-slate-500 uppercase italic">{{ $label }}</span>
                                <input type="number" min="0" step="1" name="prices[{{ $type }}]" value="{{ optional($current->get($type))->unit_price ? (int) $current->get($type)->unit_price : '' }}"
                                       placeholder="—" class="w-32 bg-white border-none rounded-lg p-2 text-right font-black text-xs shadow-inner outline-none">
                            </div>
                            @endforeach
                        </div>
                    </details>

                    <div class="text-right mt-4">
                        <button type="submit" class="bg-teal-600 text-white px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-teal-700 transition-all"><i class="fa-solid fa-floppy-disk mr-1"></i>{{ __('Enregistrer') }}</button>
                    </div>
                </form>
            @empty
                <div class="text-center text-slate-300 font-black uppercase text-[10px] tracking-widest italic py-8">{{ __('Aucun tarif. Créez-en un ci-dessus.') }}</div>
            @endforelse
        </div>
    </div>
</x-app-layout>
