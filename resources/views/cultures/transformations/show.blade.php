<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-industry text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $transformation->input_product }} → {{ $transformation->output_product }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ $transformation->batch_number }} · {{ $transformation->type_label }}</p>
                </div>
            </div>
            <a href="{{ route('crop-transformations.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Retour") }}
            </a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Entrée") }}</p>
                    <p class="text-2xl font-black text-slate-900 leading-none">{{ number_format($transformation->input_quantity, 0, ',', ' ') }} <small class="text-[10px] opacity-40">{{ $transformation->input_unit }}</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Sortie") }}</p>
                    <p class="text-2xl font-black text-green-600 leading-none">{{ number_format($transformation->output_quantity, 0, ',', ' ') }} <small class="text-[10px] opacity-40">{{ $transformation->output_unit }}</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Rendement") }}</p>
                    <p class="text-2xl font-black leading-none">{{ number_format($transformation->yield_percent, 1, ',', ' ') }} <small class="text-[10px] opacity-40">%</small></p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Valeur produit") }}</p>
                    <p class="text-2xl font-black text-slate-900 leading-none">{{ number_format($transformation->estimated_value, 0, ',', ' ') }} <small class="text-[10px] opacity-40">GNF</small></p>
                </div>
            </div>

            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-4">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-4">{{ __("Détails") }}</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-6 text-[11px]">
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Date production") }}</p><p class="font-black text-slate-800">{{ $transformation->production_date?->format('d/m/Y') }}</p></div>
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Péremption") }}</p><p class="font-black {{ $transformation->is_expired ? 'text-rose-600' : 'text-slate-800' }}">{{ $transformation->expiry_date?->format('d/m/Y') ?? '—' }}</p></div>
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Coût production") }}</p><p class="font-black text-slate-800">{{ number_format($transformation->production_cost, 0, ',', ' ') }} GNF</p></div>
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Cycle d'origine") }}</p><p class="font-black text-slate-800">{{ $transformation->cropCycle?->crop_name ?? '—' }}</p></div>
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Responsable") }}</p><p class="font-black text-slate-800">{{ $transformation->employee ? $transformation->employee->first_name.' '.$transformation->employee->last_name : '—' }}</p></div>
                    <div>
                        <p class="text-[8px] text-slate-400 uppercase">{{ __("Stock") }}</p>
                        <p class="font-black text-slate-800 flex gap-2">
                            @if($transformation->consumed_from_stock)<span class="text-blue-500" title="{{ __('Intrant déstocké') }}"><i class="fa-solid fa-arrow-down"></i> {{ $transformation->input_stock_item }}</span>@endif
                            @if($transformation->synced_to_stock)<span class="text-green-600" title="{{ __('Produit stocké') }}"><i class="fa-solid fa-arrow-up"></i> {{ $transformation->output_stock_item }}</span>@endif
                            @if(!$transformation->consumed_from_stock && !$transformation->synced_to_stock)—@endif
                        </p>
                    </div>
                </div>
                @if($transformation->notes)
                    <div class="pt-4 border-t border-slate-50">
                        <p class="text-[8px] text-slate-400 uppercase mb-1">{{ __("Notes") }}</p>
                        <p class="text-[11px] font-medium text-slate-600">{{ $transformation->notes }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
