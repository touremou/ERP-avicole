<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🐾 Gestion des Espèces')" :subtitle="__('Activer / désactiver les espèces disponibles sur ce site')" icon="fa-paw" accent="slate">
            <x-slot name="actions">
                <a href="{{ route('settings.index') }}" class="flex items-center justify-center w-11 h-11 bg-white border border-slate-200 text-slate-400 hover:text-rose-600 rounded-xl transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10 italic font-bold">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-10">

            <x-flash />

            @foreach($families as $familyKey => $familyMeta)
                @if(isset($species[$familyKey]))
                <div class="text-left">
                    <div class="flex items-center gap-3 mb-6">
                        <span class="text-3xl">{{ $familyMeta['icon'] }}</span>
                        <div>
                            <h3 class="text-lg font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ $familyMeta['label'] }}</h3>
                            <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest mt-1">{{ __(":count espèce(s)", ['count' => $species[$familyKey]->count()]) }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        @foreach($species[$familyKey] as $sp)
                        <div @class([
                            'bg-white rounded-[2.5rem] border shadow-sm transition-all duration-300 overflow-hidden',
                            'border-slate-100 opacity-60' => !$sp->is_active,
                            'border-' . $sp->color . '-100 shadow-' . $sp->color . '-50' => $sp->is_active,
                        ])>
                            <div class="p-7">
                                <div class="flex justify-between items-start mb-5">
                                    <div class="flex items-center gap-4">
                                        <div @class([
                                            'w-14 h-14 rounded-2xl flex items-center justify-center text-2xl shadow-inner',
                                            'bg-' . $sp->color . '-50' => $sp->is_active,
                                            'bg-slate-50' => !$sp->is_active,
                                        ])>{{ $sp->icon }}</div>
                                        <div>
                                            <p class="text-base font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ $sp->name_fr }}</p>
                                            @if($sp->local_name)
                                            <p class="text-[8px] font-black text-slate-300 italic mt-1 leading-none">{{ $sp->local_name }}</p>
                                            @endif
                                            <p class="text-[8px] font-black text-slate-400 uppercase mt-1 leading-none">{{ $sp->unit_label }} · {{ $sp->habitat_label }}</p>
                                        </div>
                                    </div>
                                    @can('admin.S')
                                    <form action="{{ route('admin.species.toggle', $sp) }}" method="POST">
                                        @csrf @method('PATCH')
                                        <button type="submit" @class([
                                            'relative w-12 h-6 rounded-full transition-all duration-300 shadow-inner focus:outline-none',
                                            'bg-emerald-500' => $sp->is_active,
                                            'bg-slate-200'   => !$sp->is_active,
                                        ])>
                                            <span @class([
                                                'absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-all duration-300',
                                                'left-6' => $sp->is_active,
                                                'left-0.5' => !$sp->is_active,
                                            ])></span>
                                        </button>
                                    </form>
                                    @endcan
                                </div>

                                {{-- Types de production --}}
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($sp->productionTypes->where('is_active', true) as $pt)
                                    <span class="px-3 py-1 bg-slate-50 rounded-xl text-[8px] font-black text-slate-500 uppercase italic border border-slate-100">
                                        {{ $pt->name_fr }}
                                    </span>
                                    @endforeach
                                </div>

                                {{-- Indicateurs suivis --}}
                                <div class="flex gap-2 mt-4">
                                    @if($sp->tracks_eggs)
                                    <span class="px-2 py-1 bg-yellow-50 rounded-lg text-[7px] font-black text-yellow-600 border border-yellow-100">{{ __("🥚 Œufs") }}</span>
                                    @endif
                                    @if($sp->tracks_milk)
                                    <span class="px-2 py-1 bg-blue-50 rounded-lg text-[7px] font-black text-blue-600 border border-blue-100">{{ __("🥛 Lait") }}</span>
                                    @endif
                                    @if($sp->tracks_water_quality)
                                    <span class="px-2 py-1 bg-cyan-50 rounded-lg text-[7px] font-black text-cyan-600 border border-cyan-100">{{ __("💧 Eau") }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            @endforeach

        </div>
    </div>
</x-app-layout>
