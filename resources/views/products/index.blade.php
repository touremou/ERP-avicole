<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-teal-600 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-box-open text-lg"></i></div>
                <div>
                    <h2 class="font-black text-xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __('Catalogue d\'articles') }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __('Produits vendables (photo, prix, catégorie)') }}</p>
                </div>
            </div>
            @can('commerce.C')
            <a href="{{ route('products.create') }}" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-teal-600 transition-all shadow-xl italic no-underline"><i class="fa-solid fa-plus mr-2"></i>{{ __('Nouvel article') }}</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 text-left">

            <x-flash />

            @if($products->isEmpty())
                <div class="text-center text-slate-300 font-black uppercase text-[10px] tracking-widest italic py-16">{{ __('Aucun article. Créez-en un pour faciliter la vente.') }}</div>
            @else
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach($products as $product)
                <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden {{ $product->is_active ? '' : 'opacity-50' }}">
                    <div class="h-32 bg-slate-50 flex items-center justify-center overflow-hidden">
                        @if($product->photo_url)
                            <img src="{{ $product->photo_url }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                        @else
                            <i class="fa-solid fa-image text-3xl text-slate-200"></i>
                        @endif
                    </div>
                    <div class="p-4">
                        <p class="font-black text-slate-800 text-sm leading-tight uppercase truncate">{{ $product->name }}</p>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-1">{{ $types[$product->product_type] ?? $product->product_type }}</p>
                        <p class="text-teal-600 font-black text-sm mt-2">{{ number_format($product->base_price, 0, ',', ' ') }} {{ currency() }} <span class="text-[8px] text-slate-400">/ {{ $product->unit }}</span></p>
                        <div class="flex items-center gap-3 mt-3">
                            @can('commerce.M')
                            <a href="{{ route('products.edit', $product) }}" class="text-[9px] font-black text-blue-600 uppercase tracking-widest no-underline hover:text-blue-800"><i class="fa-solid fa-pen mr-1"></i>{{ __('Modifier') }}</a>
                            @endcan
                            @can('commerce.S')
                            <form action="{{ route('products.destroy', $product) }}" method="POST" onsubmit="return confirm(@json(__('Supprimer cet article ?')))" class="inline">
                                @csrf @method('DELETE')
                                <button class="text-[9px] font-black text-rose-400 uppercase tracking-widest hover:text-rose-600 bg-transparent border-none cursor-pointer"><i class="fa-solid fa-trash-can mr-1"></i>{{ __('Suppr.') }}</button>
                            </form>
                            @endcan
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            <div class="mt-6">{{ $products->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
