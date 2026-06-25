<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Campagnes saisonnières") }}</h2>
                <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Tabaski · Ramadan · Fêtes — pilotage de la marge") }}</p>
            </div>
            @can('elevage.C')
            <a href="{{ route('campaigns.create') }}" class="bg-emerald-600 text-white px-6 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-700 transition-all no-underline shadow-lg">
                <i class="fa-solid fa-plus mr-1"></i> {{ __("Nouvelle campagne") }}
            </a>
            @endcan
        </div>
    </x-slot>

    @php
        // Classes littérales (Tailwind compilé sans safelist → pas d'interpolation dynamique).
        $statusClasses = [
            'preparation'   => ['badge' => 'bg-slate-100 text-slate-700',     'bar' => 'bg-slate-500',   'soft' => 'bg-slate-50'],
            'engraissement' => ['badge' => 'bg-amber-100 text-amber-700',     'bar' => 'bg-amber-500',   'soft' => 'bg-amber-50'],
            'vente'         => ['badge' => 'bg-emerald-100 text-emerald-700', 'bar' => 'bg-emerald-500', 'soft' => 'bg-emerald-50'],
            'cloturee'      => ['badge' => 'bg-blue-100 text-blue-700',       'bar' => 'bg-blue-500',    'soft' => 'bg-blue-50'],
        ];
    @endphp

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-6 p-5 bg-emerald-50 text-emerald-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-emerald-200">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-6 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">{{ session('error') }}</div>
            @endif

            @forelse($campaigns as $campaign)
                @php $sc = $statusClasses[$campaign->status] ?? $statusClasses['preparation']; @endphp
                <a href="{{ route('campaigns.show', $campaign) }}" class="block mb-5 bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm hover:shadow-2xl transition-all no-underline">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div class="flex items-center gap-5">
                            <div class="w-16 h-16 rounded-[1.5rem] {{ $sc['soft'] }} flex items-center justify-center text-2xl shadow-inner">
                                @if($campaign->type === 'tabaski') 🐑 @elseif($campaign->type === 'ramadan') 🌙 @else 🎉 @endif
                            </div>
                            <div>
                                <h3 class="font-black text-slate-900 text-xl uppercase italic leading-none tracking-tighter">{{ $campaign->name }}</h3>
                                <div class="flex items-center gap-2 mt-2">
                                    <span class="px-3 py-1 {{ $sc['badge'] }} rounded-lg text-[8px] font-black uppercase tracking-widest">{{ $campaign->status_label }}</span>
                                    <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ $campaign->type_label }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-8 items-center text-right">
                            <div>
                                <p class="text-2xl font-black italic tracking-tighter leading-none mb-1 {{ $campaign->is_urgent ? 'text-rose-600' : 'text-slate-900' }}">
                                    {{ $campaign->days_until_target >= 0 ? __("J-:days", ['days' => $campaign->days_until_target]) : __("Passé") }}
                                </p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ $campaign->target_date->translatedFormat('d M Y') }}</p>
                            </div>
                            <div class="border-l border-slate-100 pl-8">
                                <p class="text-2xl font-black text-slate-900 italic tracking-tighter leading-none mb-1">
                                    {{ number_format($campaign->head_count) }}@if($campaign->target_head_count)<small class="text-sm text-slate-300">/{{ number_format($campaign->target_head_count) }}</small>@endif
                                </p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Têtes · :count lot(s)", ['count' => $campaign->batches->count()]) }}</p>
                            </div>
                            <div class="border-l border-slate-100 pl-8 hidden md:block">
                                @php $marge = $campaign->target_sale_price ? $campaign->projected_margin : $campaign->realized_margin; @endphp
                                <p class="text-xl font-black italic tracking-tighter leading-none mb-1 {{ $marge >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                    {{ number_format($marge) }}
                                </p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ ($campaign->target_sale_price ? __("Marge projetée") : __("Marge réalisée")) }} ({{ currency() }})</p>
                            </div>
                        </div>
                    </div>

                    @if($campaign->target_head_count)
                        <div class="w-full bg-slate-50 h-2 rounded-full mt-6 overflow-hidden border border-slate-100">
                            <div class="{{ $sc['bar'] }} h-full rounded-full" style="width: {{ $campaign->head_progress }}%"></div>
                        </div>
                    @endif
                </a>
            @empty
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-sm text-center">
                    <div class="text-6xl mb-6">🐑</div>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Aucune campagne") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic mb-8">{{ __("Créez votre première campagne Tabaski pour piloter l'engraissement et la vente groupée.") }}</p>
                    @can('elevage.C')
                    <a href="{{ route('campaigns.create') }}" class="inline-block bg-emerald-600 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-700 transition-all no-underline shadow-lg">
                        <i class="fa-solid fa-plus mr-1"></i> {{ __("Créer une campagne") }}
                    </a>
                    @endcan
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
