<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-{{ $species->type_color }}-500 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid {{ $species->type_icon }} text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $species->name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">
                        {{ $species->type_label }}@if($species->local_name) · 🗣 {{ $species->local_name }}@endif
                    </p>
                </div>
            </div>
            <a href="{{ route('crop-catalogue.index') }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-arrow-left mr-2"></i> {{ __("Catalogue") }}
            </a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">
            @if(session('success'))
                <div class="p-5 bg-emerald-500 text-white rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic">
                    <i class="fa-solid fa-check-double mr-3 text-lg"></i> {{ session('success') }}
                </div>
            @endif

            {{-- FICHE --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Famille") }}</p>
                    <p class="text-sm font-black text-slate-900 leading-tight">{{ $species->family ?? '—' }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Cycle") }}</p>
                    <p class="text-sm font-black text-slate-900 leading-tight">{{ $species->cycle_label ?? '—' }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Rendement") }}</p>
                    <p class="text-sm font-black text-slate-900 leading-tight">{{ $species->avg_yield_tha ? number_format($species->avg_yield_tha, 2, ',', ' ').' t/ha' : '—' }}</p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Variétés") }}</p>
                    <p class="text-2xl font-black leading-none">{{ $species->varieties->count() }}</p>
                </div>
            </div>

            @if($species->description)
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm text-[11px] text-slate-600 not-italic">{{ $species->description }}</div>
            @endif

            {{-- VARIÉTÉS --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[11px] font-black uppercase text-slate-700 tracking-widest italic mb-6">{{ __("Variétés") }}</h3>
                @forelse($species->varieties as $v)
                    <div class="flex items-center justify-between p-4 mb-2 bg-slate-50 rounded-[1.5rem]">
                        <div>
                            <p class="text-[12px] font-black uppercase text-slate-800 italic leading-none">{{ $v->name }}</p>
                            <p class="text-[9px] text-slate-400 uppercase mt-1">
                                @if($v->cycle_type){{ $v->cycle_type }} · @endif
                                @if($v->cycle_days){{ $v->cycle_days }} j @endif
                                @if($v->avg_yield_tha) · {{ number_format($v->avg_yield_tha, 2, ',', ' ') }} t/ha @endif
                            </p>
                            @if($v->notes)<p class="text-[10px] text-slate-500 mt-1 not-italic">{{ $v->notes }}</p>@endif
                        </div>
                        @can('cultures.S')
                        <form action="{{ route('crop-catalogue.varieties.destroy', $v) }}" method="POST" onsubmit="return confirm('Supprimer cette variété ?')">
                            @csrf @method('DELETE')
                            <button class="text-rose-400 hover:text-rose-600 text-xs"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        @endcan
                    </div>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-6">{{ __("Aucune variété") }}</p>
                @endforelse

                @can('cultures.C')
                <form action="{{ route('crop-catalogue.varieties.store', $species) }}" method="POST" class="mt-6 pt-6 border-t border-slate-50 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Variété *") }}</label>
                        <input type="text" name="name" required class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px]">
                    </div>
                    <div>
                        <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle (j)") }}</label>
                        <input type="number" min="1" name="cycle_days" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                    </div>
                    <div>
                        <label class="block text-[8px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Rdt (t/ha)") }}</label>
                        <input type="number" step="0.01" min="0" name="avg_yield_tha" class="w-full bg-slate-50 border-none rounded-2xl p-3 font-black text-slate-800 shadow-inner italic text-[11px] text-right">
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-4 py-3 rounded-2xl font-black uppercase italic tracking-widest text-[9px] shadow-lg hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}
                    </button>
                </form>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
