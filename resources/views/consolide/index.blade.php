@php
    /**
     * Vue consolidée MULTI-SITES — la page du promoteur.
     *
     * Mêmes lignes pour chaque site, côte à côte : Kindia et Kérouané se lisent
     * d'un coup d'œil, sans basculer de ferme. Les données viennent de
     * ConsolidatedSitesService, borné au farm_user de l'utilisateur.
     */
    $currency = setting('general.currency', 'GNF');
    $mortalityThreshold = \App\Models\Batch::cumulativeMortalityThreshold();
    $mortalityWarning = \App\Models\Batch::cumulativeMortalityWarningThreshold();

    /** Colonne du site le plus « à surveiller » sur une ligne donnée. */
    $toneClass = fn (string $tone) => match ($tone) {
        'ok'   => 'text-green-600',
        'warn' => 'text-amber-600',
        'bad'  => 'text-rose-600',
        default => 'text-slate-600',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header
            :title="__('Vue consolidée')"
            :subtitle="count($sites) . ' ' . __('site(s)') . ' · ' . __('semaine') . ' ' . $week->isoWeek() . ' (' . $week->isoFormat('D MMM') . ' → ' . $week->copy()->endOfWeek()->isoFormat('D MMM YYYY') . ')'"
            icon="fa-layer-group" accent="indigo" :back="route('dashboard')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold space-y-8">

            {{-- SÉLECTION DE SEMAINE --}}
            <form method="GET" action="{{ route('consolide.index') }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm print:hidden">
                <div class="flex flex-wrap gap-4 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Semaine") }}</label>
                        <input type="week" name="week" value="{{ $week->format('o') }}-W{{ str_pad($week->isoWeek(), 2, '0', STR_PAD_LEFT) }}"
                               onchange="this.form.submit()" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <button type="button" onclick="window.print()" class="bg-slate-50 text-slate-600 px-8 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-slate-100 transition-all">
                        <i class="fa-solid fa-print mr-1"></i> {{ __("Imprimer") }}
                    </button>
                </div>
            </form>

            @if(count($sites) === 0)
                <div class="bg-white p-12 rounded-[3rem] border border-slate-100 text-center">
                    <p class="text-[11px] font-black text-slate-400 uppercase italic">{{ __("Aucun site accessible sur votre compte.") }}</p>
                </div>
            @else

            {{-- TOTAL DU GROUPE --}}
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                @php
                    $groupTiles = [
                        ['label' => __("Sujets vivants"), 'value' => number_format($totals['live_subjects'], 0, ',', ' '), 'sub' => $totals['active_batches'] . ' ' . __("lots")],
                        ['label' => __("Surface cultivée"), 'value' => number_format($totals['area_ha'], 2, ',', ' ') . ' ha', 'sub' => $totals['active_cycles'] . ' ' . __("cycles")],
                        ['label' => __("Complétion tâches"), 'value' => $totals['completion'] === null ? '—' : number_format($totals['completion'], 1, ',', ' ') . ' %', 'sub' => $totals['tasks_done'] . '/' . $totals['tasks_total']],
                        ['label' => __("Étapes en retard"), 'value' => $totals['late_steps'], 'sub' => __("itinéraires cultures")],
                        ['label' => __("CA de la semaine"), 'value' => number_format($totals['week_revenue'], 0, ',', ' '), 'sub' => $currency],
                        ['label' => __("Créances ouvertes"), 'value' => number_format($totals['open_receivable'], 0, ',', ' '), 'sub' => $currency],
                    ];
                @endphp
                @foreach($groupTiles as $tile)
                    <div class="bg-slate-900 text-white p-5 rounded-[2rem] shadow-lg">
                        <p class="text-[8px] font-black text-white/40 uppercase tracking-widest italic mb-2 leading-tight">{{ $tile['label'] }}</p>
                        <p class="text-xl font-black leading-none">{{ $tile['value'] }}</p>
                        <p class="text-[8px] font-bold text-white/30 uppercase italic mt-2">{{ $tile['sub'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- COMPARAISON SITE PAR SITE --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm overflow-x-auto">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-2">{{ __("Les sites côte à côte") }}</h3>
                <p class="text-[9px] font-bold text-slate-400 uppercase italic mb-6">
                    {{ __("Mêmes lignes pour chaque site — le point à distance porte sur les écarts, pas sur ce qui est vert.") }}
                </p>

                <table class="w-full text-left min-w-[600px]">
                    <thead>
                        <tr class="border-b-2 border-slate-100">
                            <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Indicateur") }}</th>
                            @foreach($sites as $site)
                                <th class="pb-3 px-4 text-[9px] font-black text-slate-800 uppercase tracking-widest text-right whitespace-nowrap">
                                    {{ $site['farm']->name }}
                                    {{-- Un site DÉSACTIVÉ figure encore dans les semaines
                                         où il a produit — le passé ne se réécrit pas — mais
                                         il doit se signaler, sinon on lit ses chiffres comme
                                         ceux d'un site en service. --}}
                                    @if($site['inactive'] ?? false)
                                        <span class="block text-[7px] font-black text-amber-600 normal-case tracking-normal">{{ __("désactivé") }}</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            /**
                             * Lignes déclarées une fois, rendues pour chaque site :
                             * la garantie que les colonnes sont comparables.
                             * `tone` colore la cellule quand un seuil est franchi.
                             */
                            $rows = [
                                ['group' => __("Élevage"), 'label' => __("Lots actifs"), 'get' => fn ($s) => $s['elevage']['active_batches']],
                                ['label' => __("Sujets vivants"), 'get' => fn ($s) => number_format($s['elevage']['live_subjects'], 0, ',', ' ')],
                                ['label' => __("Mortalité — pire lot"), 'get' => fn ($s) => $s['elevage']['worst_mortality'] === null ? null : number_format($s['elevage']['worst_mortality'], 2, ',', ' ') . ' %',
                                 'tone' => fn ($s) => $s['elevage']['worst_mortality'] === null ? 'neutral' : ($s['elevage']['worst_mortality'] >= $mortalityThreshold ? 'bad' : ($s['elevage']['worst_mortality'] >= $mortalityWarning ? 'warn' : 'ok'))],
                                ['label' => __("FCR moyen"), 'get' => fn ($s) => $s['elevage']['avg_fcr'] === null ? null : number_format($s['elevage']['avg_fcr'], 2, ',', ' ')],

                                ['group' => __("Cultures"), 'label' => __("Cycles en cours"), 'get' => fn ($s) => $s['cultures']['active_cycles']],
                                ['label' => __("Surface (ha)"), 'get' => fn ($s) => number_format($s['cultures']['area_ha'], 2, ',', ' ')],
                                ['label' => __("Étapes d'itinéraire en retard"), 'get' => fn ($s) => $s['cultures']['late_steps'],
                                 'tone' => fn ($s) => $s['cultures']['late_steps'] > 3 ? 'bad' : ($s['cultures']['late_steps'] > 0 ? 'warn' : 'ok')],

                                ['group' => __("Exécution"), 'label' => __("Complétion des tâches"), 'get' => fn ($s) => $s['tasks']['completion'] === null ? null : number_format($s['tasks']['completion'], 1, ',', ' ') . ' %',
                                 'tone' => fn ($s) => $s['tasks']['completion'] === null ? 'neutral' : ($s['tasks']['completion'] >= 90 ? 'ok' : ($s['tasks']['completion'] >= 75 ? 'warn' : 'bad'))],
                                ['label' => __("Tâches en retard"), 'get' => fn ($s) => $s['tasks']['late'],
                                 'tone' => fn ($s) => $s['tasks']['late'] > 5 ? 'bad' : ($s['tasks']['late'] > 0 ? 'warn' : 'ok')],

                                ['group' => __("Sanitaire & magasin"), 'label' => __("Incidents ouverts"), 'get' => fn ($s) => $s['sanitaire']['open_incidents'],
                                 'tone' => fn ($s) => $s['sanitaire']['open_incidents'] > 0 ? 'warn' : 'ok'],
                                ['label' => __("Articles sous seuil"), 'get' => fn ($s) => $s['stock']['low_items'] . ' / ' . $s['stock']['items'],
                                 'tone' => fn ($s) => $s['stock']['low_items'] > 0 ? 'warn' : 'ok'],

                                ['group' => __("Commerce"), 'label' => __("CA de la semaine") . " ({$currency})", 'get' => fn ($s) => number_format($s['commerce']['week_revenue'], 0, ',', ' ')],
                                ['label' => __("Créances ouvertes") . " ({$currency})", 'get' => fn ($s) => number_format($s['commerce']['open_receivable'], 0, ',', ' '),
                                 'sub' => fn ($s) => $s['commerce']['open_count'] . ' ' . __("vente(s)")],
                            ];
                        @endphp

                        @foreach($rows as $row)
                            @if(isset($row['group']))
                                <tr>
                                    <td colspan="{{ count($sites) + 1 }}" class="pt-6 pb-2 text-[8px] font-black text-indigo-500 uppercase tracking-[0.2em]">{{ $row['group'] }}</td>
                                </tr>
                            @endif
                            <tr class="border-b border-slate-50">
                                <td class="py-3 text-[10px] font-bold text-slate-500 uppercase italic">{{ $row['label'] }}</td>
                                @foreach($sites as $site)
                                    @php
                                        $value = ($row['get'])($site);
                                        $tone = isset($row['tone']) ? ($row['tone'])($site) : 'neutral';
                                    @endphp
                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                        @if($value === null)
                                            {{-- Donnée absente ≠ zéro. --}}
                                            <span class="text-[10px] font-black text-slate-300 italic">{{ __("n/d") }}</span>
                                        @else
                                            <span class="text-[12px] font-black {{ $toneClass($tone) }}">{{ $value }}</span>
                                            @isset($row['sub'])
                                                <span class="block text-[8px] font-bold text-slate-400 uppercase">{{ ($row['sub'])($site) }}</span>
                                            @endisset
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- ÉQUIPES PAR SITE --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                @foreach($sites as $site)
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic">
                                {{ $site['farm']->name }} — {{ __("équipe") }}
                            </h3>
                            @if(count($site['team']) === 1)
                                {{-- Site à un seul agent : pas de contrôle croisé possible.
                                     C'est le site le plus exposé au risque d'angle mort. --}}
                                <span class="text-[8px] px-2 py-1 rounded-full font-black uppercase bg-amber-50 text-amber-600">
                                    {{ __("agent isolé") }}
                                </span>
                            @endif
                        </div>
                        @forelse($site['team'] as $member)
                            <div class="flex items-center justify-between py-3 border-b border-slate-50 last:border-0">
                                <div>
                                    <p class="text-[11px] font-black uppercase text-slate-800 italic">{{ $member['name'] }}</p>
                                    <p class="text-[8px] font-bold text-slate-400 uppercase">
                                        {{ $member['job_title'] ?? '—' }} ·
                                        {{ $member['tasks']['done'] }}/{{ $member['tasks']['total'] }} {{ __("tâches") }}
                                        @if($member['tasks']['late'] > 0) · {{ $member['tasks']['late'] }} {{ __("en retard") }} @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-black {{ $toneClass($member['tone']) }} leading-none">
                                        {{ $member['completion'] === null ? '—' : number_format($member['completion'], 0, ',', ' ') . ' %' }}
                                    </p>
                                    <a href="{{ route('rh.semaine', ['employee_id' => $member['id'], 'week' => $week->format('o') . '-W' . str_pad($week->isoWeek(), 2, '0', STR_PAD_LEFT)]) }}"
                                       class="text-[8px] font-black uppercase text-indigo-500 hover:text-indigo-700 no-underline italic">
                                        {{ __("sa fiche") }} →
                                    </a>
                                </div>
                            </div>
                        @empty
                            <p class="text-[10px] font-bold text-slate-300 uppercase italic">{{ __("Aucune tâche planifiée cette semaine sur ce site.") }}</p>
                        @endforelse
                    </div>
                @endforeach
            </div>

            <p class="text-[9px] font-bold text-slate-400 uppercase italic text-center px-8 leading-relaxed">
                {{ __("Périmètre : uniquement les sites rattachés à votre compte. La ponctualité de saisie est mesurée sur la date déclarée de l'acte — un site sans couverture réseau n'est pas pénalisé.") }}
            </p>

            @endif
        </div>
    </div>
</x-app-layout>
