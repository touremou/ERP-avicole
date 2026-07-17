<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🤝 Annuaire / Tiers')" :subtitle="__('Fournisseurs · Partenaires')" icon="fa-address-book" accent="orange">
            <x-slot name="actions">
                @can('annuaire.C')
                <a href="{{ route('providers.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-orange-600 transition-all no-underline shadow-lg italic"><i class="fa-solid fa-truck-field"></i> {{ __("Nouveau fournisseur") }}</a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Fournisseurs") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['providers'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Actifs") }}</p>
                    <p class="text-2xl font-black text-emerald-600 leading-none">{{ $kpis['providers_active'] }}</p>
                </div>
            </div>

            <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-widest text-orange-500 mb-4">{{ __("Partenaires") }}</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @can('annuaire.L')
                    <a href="{{ route('providers.index') }}" class="flex flex-col items-center justify-center gap-2 p-6 bg-slate-50 rounded-2xl hover:bg-orange-50 hover:text-orange-600 transition-all no-underline text-slate-600 text-center">
                        <i class="fa-solid fa-truck-field text-lg"></i>
                        <span class="text-[8px] font-black uppercase tracking-widest leading-tight">{{ __("Fournisseurs") }}</span>
                    </a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
