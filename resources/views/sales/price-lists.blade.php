<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <div class="w-12 h-12 bg-teal-600 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-tags text-lg"></i></div>
            <div>
                <h2 class="font-black text-xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __('Groupes de prix') }}</h2>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __('Tarifs détail / grossiste par type de produit') }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 text-left space-y-6">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                <div @class(['p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-sm',
                    'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>{{ session($msg) }}</div>
                @endif
            @endforeach

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
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($productTypes as $type => $label)
                        <div class="flex items-center justify-between gap-3 p-3 rounded-xl bg-slate-50">
                            <span class="text-[11px] font-black text-slate-600 uppercase italic">{{ $label }}</span>
                            <input type="number" min="0" step="1" name="prices[{{ $type }}]" value="{{ optional($current->get($type))->unit_price ? (int) $current->get($type)->unit_price : '' }}"
                                   placeholder="—" class="w-32 bg-white border-none rounded-lg p-2 text-right font-black text-xs shadow-inner outline-none">
                        </div>
                        @endforeach
                    </div>

                    @if($products->isNotEmpty())
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-6 mb-2 italic">{{ __('Prix par article') }} <span class="text-slate-300 normal-case">({{ __('prioritaire sur la catégorie ; vide = prix de base de l\'article') }})</span></p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($products as $product)
                        <div class="flex items-center justify-between gap-3 p-3 rounded-xl bg-teal-50/40">
                            <span class="text-[11px] font-black text-slate-600 italic truncate">{{ $product->name }}</span>
                            <input type="number" min="0" step="1" name="article_prices[{{ $product->id }}]" value="{{ optional($articleCurrent->get($product->id))->unit_price ? (int) $articleCurrent->get($product->id)->unit_price : '' }}"
                                   placeholder="{{ (int) $product->base_price }}" class="w-32 bg-white border-none rounded-lg p-2 text-right font-black text-xs shadow-inner outline-none">
                        </div>
                        @endforeach
                    </div>
                    @endif

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
