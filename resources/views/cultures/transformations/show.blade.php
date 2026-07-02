@php $currency = setting('general.currency', 'GNF'); @endphp
<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$transformation->input_product . ' → ' . $transformation->output_product" :subtitle="$transformation->batch_number . ' · ' . $transformation->type_label" icon="fa-industry" accent="green" :back="route('crop-transformations.index')">
            <x-slot name="actions">
                @if($transformation->batch_number)
                <a href="{{ route('crop-transformations.label', $transformation) }}" target="_blank" class="bg-green-50 text-green-700 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2 hover:bg-green-600 hover:text-white transition" title="{{ __('Étiquette QR de traçabilité') }}">
                    <i class="fa-solid fa-qrcode"></i> {{ __("Étiquette") }}
                </a>
                @endif
                @can('cultures.M')
                <a href="{{ route('crop-transformations.edit', $transformation) }}" class="bg-white border border-slate-100 text-slate-600 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest italic no-underline flex items-center gap-2 hover:bg-slate-50">
                    <i class="fa-solid fa-pen text-green-500"></i> {{ __("Modifier") }}
                </a>
                @endcan
                @can('cultures.S')
                <form action="{{ route('crop-transformations.destroy', $transformation) }}" method="POST" onsubmit="return confirm('Supprimer cette transformation ?')">
                    @csrf @method('DELETE')
                    <button class="text-rose-400 hover:text-rose-600 text-[10px] font-black uppercase italic"><i class="fa-solid fa-trash mr-1"></i>{{ __("Supprimer") }}</button>
                </form>
                @endcan
            </x-slot>
        </x-page-header>
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
                    <p class="text-2xl font-black text-slate-900 leading-none">{{ number_format($transformation->estimated_value, 0, ',', ' ') }} <small class="text-[10px] opacity-40">{{ $currency }}</small></p>
                </div>
            </div>

            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-4">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-4">{{ __("Détails") }}</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-6 text-[11px]">
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Date production") }}</p><p class="font-black text-slate-800">{{ $transformation->production_date?->format('d/m/Y') }}</p></div>
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Péremption") }}</p><p class="font-black {{ $transformation->is_expired ? 'text-rose-600' : 'text-slate-800' }}">{{ $transformation->expiry_date?->format('d/m/Y') ?? '—' }}</p></div>
                    <div><p class="text-[8px] text-slate-400 uppercase">{{ __("Coût production") }}</p><p class="font-black text-slate-800">{{ number_format($transformation->production_cost, 0, ',', ' ') }} {{ $currency }}</p></div>
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
