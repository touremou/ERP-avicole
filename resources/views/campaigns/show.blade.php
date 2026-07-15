<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="($campaign->type === 'tabaski' ? '🐑 ' : ($campaign->type === 'ramadan' ? '🌙 ' : '🎉 ')) . $campaign->name" :subtitle="$campaign->type_label . ' · ' . $campaign->status_label" icon="fa-calendar-week" accent="emerald" :back="route('campaigns.index')">
            <x-slot name="actions">
                @can('elevage.M')
                <a href="{{ route('campaigns.edit', $campaign) }}" class="bg-white border border-slate-200 text-slate-600 px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all no-underline shadow-sm">
                    <i class="fa-solid fa-pen mr-1"></i> {{ __("Modifier") }}
                </a>
                @endcan
                @can('elevage.S')
                <form action="{{ route('campaigns.destroy', $campaign) }}" method="POST" onsubmit="return confirm({{ Js::from(__("Supprimer cette campagne ? Les lots seront détachés mais conservés.")) }});">
                    @csrf @method('DELETE')
                    <button type="submit" class="bg-red-50 text-red-500 px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-500 hover:text-white transition-all border-none cursor-pointer shadow-sm">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </form>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10 italic font-bold text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-8">

            <x-flash />

            {{-- COMPTE À REBOURS --}}
            <div class="bg-slate-900 p-10 rounded-[3rem] text-white shadow-2xl flex flex-col md:flex-row items-center justify-between gap-6">
                <div>
                    <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] italic mb-2">{{ __("Compte à rebours — pic de vente") }}</p>
                    <p class="text-5xl font-black italic tracking-tighter leading-none {{ $campaign->is_urgent ? 'text-rose-400' : 'text-white' }}">
                        {{ $campaign->days_until_target >= 0 ? 'J-'.$campaign->days_until_target : __("Échéance passée") }}
                    </p>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-3">{{ $campaign->target_date->translatedFormat('l d F Y') }}</p>
                </div>
                @if($campaign->target_head_count)
                <div class="text-center">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ __("Avancement effectif") }}</p>
                    <p class="text-4xl font-black italic text-emerald-400">{{ $campaign->head_progress }}%</p>
                    <p class="text-[9px] font-black text-slate-500 uppercase mt-1">{{ number_format($campaign->head_count) }} / {{ number_format($campaign->target_head_count) }} {{ __("têtes") }}</p>
                </div>
                @endif
            </div>

            {{-- KPI FINANCIERS --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 italic">{{ __("Coût total engagé") }}</p>
                    <p class="text-2xl font-black text-slate-900 italic tracking-tighter">{{ number_format($campaign->total_cost) }}</p>
                    <p class="text-[8px] text-slate-400 mt-2 uppercase font-black">{{ __("Acquisition + aliment + santé") }}</p>
                </div>
                <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-2 italic">{{ __("CA réalisé") }}</p>
                    <p class="text-2xl font-black text-slate-900 italic tracking-tighter">{{ number_format($campaign->realized_revenue) }}</p>
                    <p class="text-[8px] text-emerald-600 mt-2 uppercase font-black">{{ __("Ventes validées des lots") }}</p>
                </div>
                <div class="bg-white p-7 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mb-2 italic">{{ __("CA projeté") }}</p>
                    <p class="text-2xl font-black text-slate-900 italic tracking-tighter">{{ number_format($campaign->projected_revenue) }}</p>
                    <p class="text-[8px] text-amber-600 mt-2 uppercase font-black">{{ $campaign->target_sale_price ? number_format($campaign->target_sale_price).' '.currency().'/tête' : __("Définir prix cible") }}</p>
                </div>
                @php $marge = $campaign->target_sale_price ? $campaign->projected_margin : $campaign->realized_margin; @endphp
                <div class="p-7 rounded-[2.5rem] shadow-sm {{ $marge >= 0 ? 'bg-emerald-600' : 'bg-rose-600' }} text-white">
                    <p class="text-[9px] font-black uppercase tracking-widest mb-2 italic opacity-80">{{ __("Marge") }} {{ $campaign->target_sale_price ? __("projetée") : __("réalisée") }}</p>
                    <p class="text-2xl font-black italic tracking-tighter">{{ number_format($marge) }}</p>
                    <p class="text-[8px] mt-2 uppercase font-black opacity-80">{{ currency() }}</p>
                </div>
            </div>

            {{-- LOTS RATTACHÉS --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-[11px] font-black uppercase text-slate-800 tracking-[0.2em] italic flex items-center">
                        <span class="w-2 h-6 bg-emerald-500 rounded-full mr-3"></span> {{ __("Lots rattachés") }} ({{ $campaign->batches->count() }})
                    </h3>
                </div>

                @forelse($campaign->batches as $batch)
                    <div class="flex items-center justify-between p-5 bg-slate-50 rounded-2xl mb-3">
                        <div class="flex items-center gap-4">
                            <span class="text-2xl">{{ $batch->species?->icon ?? '🐾' }}</span>
                            <div>
                                <a href="{{ route('batches.show', $batch->id) }}" class="font-black text-slate-900 text-sm uppercase italic no-underline hover:text-emerald-600">{{ $batch->code }}</a>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-1">{{ $batch->species?->name_fr ?? '—' }} · {{ $batch->building?->name ?? '—' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-5">
                            <div class="text-right">
                                <p class="text-lg font-black text-slate-900 italic leading-none">{{ number_format($batch->current_quantity) }}</p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("têtes") }}</p>
                            </div>
                            @can('elevage.M')
                            <form action="{{ route('campaigns.detachBatch', [$campaign, $batch]) }}" method="POST" onsubmit="return confirm({{ Js::from(__("Détacher ce lot de la campagne ?")) }});">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-slate-300 hover:text-red-500 border-none bg-transparent cursor-pointer" title="{{ __("Détacher") }}"><i class="fa-solid fa-link-slash"></i></button>
                            </form>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase tracking-widest italic py-6">{{ __("Aucun lot rattaché pour l'instant.") }}</p>
                @endforelse

                {{-- RATTACHER UN LOT --}}
                @can('elevage.M')
                @if($eligibleBatches->isNotEmpty())
                    <form action="{{ route('campaigns.attachBatch', $campaign) }}" method="POST" class="flex gap-3 mt-6 pt-6 border-t border-slate-100">
                        @csrf
                        <select name="batch_id" required class="flex-1 bg-slate-50 border-none rounded-2xl p-4 text-[10px] font-black uppercase shadow-inner outline-none italic">
                            <option value="">— {{ __("Rattacher un lot") }} {{ str_replace('_', ' ', $campaign->target_family) }} {{ __("actif —") }}</option>
                            @foreach($eligibleBatches as $b)
                                <option value="{{ $b->id }}">{{ $b->species?->icon }} {{ $b->code }} — {{ $b->species?->name_fr }} ({{ number_format($b->current_quantity) }} {{ __("têtes") }})</option>
                            @endforeach
                        </select>
                        <button type="submit" class="bg-emerald-600 text-white px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-700 transition-all border-none cursor-pointer">
                            <i class="fa-solid fa-link mr-1"></i> {{ __("Rattacher") }}
                        </button>
                    </form>
                @else
                    <p class="text-center text-slate-300 text-[9px] font-black uppercase tracking-widest italic mt-6 pt-6 border-t border-slate-100">
                        {{ __("Aucun lot") }} {{ str_replace('_', ' ', $campaign->target_family) }} {{ __("actif disponible à rattacher.") }}
                    </p>
                @endif
                @endcan
            </div>

            @if($campaign->notes)
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-3 italic">{{ __("Notes") }}</h3>
                    <p class="text-sm text-slate-600 font-bold italic">{{ $campaign->notes }}</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
