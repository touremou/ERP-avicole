<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-list-check text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $protocol->name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">
                        {{ $protocol->crop_name ?: __('Générique') }} <i class="fa-solid fa-circle text-[3px] mx-1 align-middle"></i> {{ $protocol->zone_label }}
                    </p>
                </div>
            </div>
            <div class="flex gap-3 items-center">
                <a href="{{ route('crop-protocols.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                    <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Itinéraires") }}
                </a>
                @can('cultures.M')
                <a href="{{ route('crop-protocols.edit', $protocol) }}" class="bg-white border border-slate-100 text-slate-600 px-5 py-2.5 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-slate-50 transition italic no-underline flex items-center gap-2">
                    <i class="fa-solid fa-pen text-green-500"></i>{{ __("Modifier") }}
                </a>
                @endcan
                @can('cultures.S')
                <form action="{{ route('crop-protocols.destroy', $protocol) }}" method="POST" onsubmit="return confirm('Supprimer cet itinéraire ?')">
                    @csrf @method('DELETE')
                    <button class="text-rose-400 hover:text-rose-600 text-[10px] font-black uppercase italic"><i class="fa-solid fa-trash mr-1"></i>{{ __("Supprimer") }}</button>
                </form>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">
            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Étapes") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $protocol->items->count() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Durée") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $protocol->duration_days }} <small class="text-[10px] opacity-40">j</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Source") }}</p>
                    <p class="text-base font-black leading-tight">{{ $protocol->source ?: '—' }}</p>
                </div>
            </div>

            @if($protocol->description)
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-[11px] text-slate-600 not-italic">{{ $protocol->description }}</div>
            @endif

            {{-- TIMELINE DE L'ITINÉRAIRE --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-700 tracking-widest italic mb-6">{{ __("Itinéraire technique") }}</h3>
                <div class="space-y-3">
                    @forelse($protocol->items as $item)
                        <div class="flex items-start gap-4 p-4 rounded-[1.5rem] bg-slate-50">
                            <div class="flex flex-col items-center justify-center w-16 shrink-0">
                                <span class="text-[8px] font-black text-slate-400 uppercase italic">{{ __("Jour") }}</span>
                                <span class="text-xl font-black text-slate-900 leading-none">J+{{ $item->day_number }}</span>
                            </div>
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 bg-{{ $item->type_color }}-100 text-{{ $item->type_color }}-600">
                                <i class="fa-solid {{ $item->type_icon }}"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-[12px] font-black uppercase text-slate-800 italic leading-none">{{ $item->action_name }}</p>
                                    <span class="text-[7px] font-black uppercase bg-{{ $item->type_color }}-100 text-{{ $item->type_color }}-700 px-2 py-0.5 rounded-full italic">{{ $item->type_label }}</span>
                                    @if($item->stage)<span class="text-[8px] font-black text-slate-400 uppercase italic">{{ $item->stage }}</span>@endif
                                    @include('cultures.protocols._item-info', ['item' => $item, 'align' => 'left'])
                                </div>
                                @if($item->product_suggested || $item->dose)
                                    <p class="text-[10px] font-bold text-slate-500 italic mt-1.5">
                                        @if($item->product_suggested){{ $item->product_suggested }}@endif
                                        @if($item->dose)<span class="text-green-600"> — {{ $item->dose }}</span>@endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-6">{{ __("Aucune étape définie") }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
