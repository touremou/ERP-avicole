@php
    /**
     * Fiche de suivi HEBDOMADAIRE — support du rituel du lundi.
     *
     * Un seul écran, imprimable : le technicien s'auto-suit, le promoteur ne
     * regarde que les écarts. Les six indicateurs sont produits par
     * TechnicianWeekService, source unique partagée avec l'API mobile.
     */
    $toneClasses = [
        'ok'      => ['bg' => 'bg-green-50',  'text' => 'text-green-600',  'ring' => 'ring-green-100'],
        'warn'    => ['bg' => 'bg-amber-50',  'text' => 'text-amber-600',  'ring' => 'ring-amber-100'],
        'bad'     => ['bg' => 'bg-rose-50',   'text' => 'text-rose-600',   'ring' => 'ring-rose-100'],
        'neutral' => ['bg' => 'bg-slate-50',  'text' => 'text-slate-500',  'ring' => 'ring-slate-100'],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header
            :title="__('Suivi hebdomadaire')"
            :subtitle="$week->isoFormat('D MMMM') . ' → ' . $week->copy()->endOfWeek()->isoFormat('D MMMM YYYY') . ' · S' . $week->isoWeek()"
            icon="fa-chart-line" accent="indigo" :back="route('rh.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold space-y-8">

            {{-- SÉLECTION : technicien + semaine --}}
            <form method="GET" action="{{ route('rh.semaine') }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm print:hidden">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Technicien") }}</label>
                        <select name="employee_id" onchange="this.form.submit()" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected($selected?->id === $emp->id)>
                                    {{ $emp->first_name }} {{ $emp->last_name }}{{ $emp->job_title ? " — {$emp->job_title}" : '' }}
                                </option>
                            @endforeach
                        </select>
                        @unless($canSeeAll)
                            <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Votre fiche personnelle") }}</p>
                        @endunless
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Semaine") }}</label>
                        <input type="week" name="week" value="{{ $week->format('o') }}-W{{ str_pad($week->isoWeek(), 2, '0', STR_PAD_LEFT) }}"
                               onchange="this.form.submit()" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div class="flex gap-2">
                        @if($canSeeAll && $selected)
                            <a href="{{ route('rh.semaine.pdf', ['employee_id' => $selected->id, 'week' => $week->format('o') . '-W' . str_pad($week->isoWeek(), 2, '0', STR_PAD_LEFT)]) }}"
                               class="flex-1 text-center bg-slate-900 text-white px-6 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] no-underline hover:bg-indigo-600 transition-all">
                                <i class="fa-solid fa-file-pdf mr-1"></i> {{ __("PDF") }}
                            </a>
                        @endif
                        <button type="button" onclick="window.print()" class="flex-1 bg-slate-50 text-slate-600 px-6 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-slate-100 transition-all">
                            <i class="fa-solid fa-print mr-1"></i> {{ __("Imprimer") }}
                        </button>
                    </div>
                </div>
            </form>

            @if(! $sheet)
                <div class="bg-white p-12 rounded-[3rem] border border-slate-100 text-center">
                    <p class="text-[11px] font-black text-slate-400 uppercase italic">{{ __("Aucun technicien actif à suivre.") }}</p>
                </div>
            @else

            {{-- LES SIX INDICATEURS --}}
            <div>
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-4 ml-2">
                    {{ $sheet['employee']->first_name }} {{ $sheet['employee']->last_name }} — {{ __("indicateurs de la semaine") }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    @foreach($sheet['indicators'] as $ind)
                        @php $c = $toneClasses[$ind['tone']] ?? $toneClasses['neutral']; @endphp
                        <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-3 leading-tight">{{ __($ind['label']) }}</p>
                            <div class="flex items-baseline gap-2">
                                @if($ind['value'] === null)
                                    {{-- Donnée absente ≠ résultat nul : un « 0 » se lirait comme conforme. --}}
                                    <span class="text-lg font-black text-slate-300 italic">{{ __("non mesurable") }}</span>
                                @else
                                    <span class="text-3xl font-black {{ $c['text'] }} leading-none">{{ number_format($ind['value'], $ind['unit'] === '%' ? 1 : 2, ',', ' ') }}</span>
                                    <span class="text-[10px] font-black {{ $c['text'] }} opacity-60">{{ $ind['unit'] }}</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 mt-3">
                                <span class="text-[8px] px-2 py-1 rounded-full font-black uppercase {{ $c['bg'] }} {{ $c['text'] }}">{{ __("cible") }} {{ $ind['target'] }}</span>
                            </div>
                            <p class="text-[9px] font-bold text-slate-400 uppercase italic mt-3 leading-relaxed">{{ $ind['detail'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {{-- LOTS SOUS RESPONSABILITÉ --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Lots sous responsabilité") }}</h3>
                    @forelse($sheet['batches'] as $b)
                        <div class="py-3 border-b border-slate-50 last:border-0">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-black uppercase text-slate-800 italic">{{ $b['code'] }}
                                    <span class="text-[8px] text-slate-400">{{ $b['building'] }} · J{{ $b['age_days'] }}</span>
                                </p>
                                <p class="text-[10px] font-black text-slate-500">{{ number_format($b['current'], 0, ',', ' ') }} {{ __("sujets") }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3 mt-2 text-[9px] font-black uppercase italic">
                                <span class="text-slate-400">{{ __("Mortalité") }}
                                    <span class="{{ $b['mortality_rate'] > 0 ? 'text-rose-500' : 'text-slate-600' }}">{{ number_format($b['mortality_rate'], 2, ',', ' ') }} %</span>
                                </span>
                                <span class="text-slate-400">{{ __("FCR") }}
                                    <span class="text-slate-600">{{ $b['fcr'] !== null ? number_format($b['fcr'], 2, ',', ' ') : '—' }}</span>
                                </span>
                                @if($b['feed_gap_percent'] !== null)
                                    <span class="text-slate-400">{{ __("Aliment") }}
                                        <span class="{{ abs($b['feed_gap_percent']) > 10 ? 'text-rose-500' : (abs($b['feed_gap_percent']) > 5 ? 'text-amber-600' : 'text-green-600') }}">
                                            {{ $b['feed_gap_percent'] > 0 ? '+' : '' }}{{ number_format($b['feed_gap_percent'], 1, ',', ' ') }} %
                                        </span>
                                    </span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-[10px] font-bold text-slate-300 uppercase italic">{{ __("Aucun lot actif sous sa responsabilité.") }}</p>
                    @endforelse
                </div>

                {{-- CULTURES + AVANCEMENT D'ITINÉRAIRE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Cultures et itinéraires") }}</h3>
                    @forelse($sheet['cycles'] as $c)
                        <div class="py-3 border-b border-slate-50 last:border-0">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-black uppercase text-slate-800 italic">{{ $c['crop_name'] }}
                                    <span class="text-[8px] text-slate-400">{{ $c['code'] }} · {{ $c['plot'] }}</span>
                                </p>
                                @if($c['days_after_planting'] !== null)
                                    <p class="text-[9px] font-black text-slate-400 uppercase">J+{{ $c['days_after_planting'] }}</p>
                                @endif
                            </div>
                            @if($c['steps_total'] > 0)
                                @php $pct = round($c['steps_done'] / $c['steps_total'] * 100); @endphp
                                <div class="flex items-center gap-3 mt-2">
                                    <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-green-500' : ($pct >= 70 ? 'bg-amber-400' : 'bg-rose-400') }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-[9px] font-black text-slate-500">{{ $c['steps_done'] }}/{{ $c['steps_total'] }}</span>
                                </div>
                                @if($c['steps_late'] > 0)
                                    <p class="text-[9px] font-black text-rose-500 uppercase italic mt-1">{{ $c['steps_late'] }} {{ __("étape(s) en retard") }}</p>
                                @endif
                            @else
                                <p class="text-[9px] font-bold text-slate-300 uppercase italic mt-1">{{ __("Aucune étape d'itinéraire échue") }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-[10px] font-bold text-slate-300 uppercase italic">{{ __("Aucune culture en cours sous sa responsabilité.") }}</p>
                    @endforelse

                    @if($sheet['incidents'] > 0)
                        <p class="text-[10px] font-black text-amber-600 uppercase italic mt-6 pt-4 border-t border-slate-50">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                            {{ $sheet['incidents'] }} {{ __("incident(s) sanitaire(s) déclaré(s) cette semaine") }}
                        </p>
                    @endif
                </div>
            </div>

            @endif

            {{-- COMPARATIF DES TECHNICIENS (promoteur uniquement) --}}
            @if($canSeeAll && count($comparison) > 1)
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm overflow-x-auto">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-2">{{ __("Comparatif de la semaine") }}</h3>
                    <p class="text-[9px] font-bold text-slate-400 uppercase italic mb-6">
                        {{ __("Le point à distance ne porte que sur les écarts — pas sur ce qui est vert.") }}
                    </p>
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-100">
                                <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Technicien") }}</th>
                                @foreach($comparison[0]['indicators'] as $ind)
                                    <th class="pb-3 px-3 text-[8px] font-black text-slate-400 uppercase tracking-widest text-right whitespace-nowrap">{{ __($ind['label']) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($comparison as $row)
                                <tr class="border-b border-slate-50 last:border-0">
                                    <td class="py-3 text-[10px] font-black uppercase text-slate-800 italic whitespace-nowrap">
                                        {{ $row['employee']->first_name }} {{ $row['employee']->last_name }}
                                    </td>
                                    @foreach($row['indicators'] as $ind)
                                        @php $c = $toneClasses[$ind['tone']] ?? $toneClasses['neutral']; @endphp
                                        <td class="py-3 px-3 text-right whitespace-nowrap">
                                            @if($ind['value'] === null)
                                                <span class="text-[10px] font-black text-slate-300">—</span>
                                            @else
                                                <span class="text-[11px] font-black {{ $c['text'] }}">
                                                    {{ number_format($ind['value'], $ind['unit'] === '%' ? 0 : 2, ',', ' ') }}{{ $ind['unit'] }}
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
