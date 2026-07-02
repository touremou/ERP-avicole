<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'📊 ' . __('Vue analytique consolidée')" :subtitle="__('Mortalité · Eau · Énergie') . ' — ' . __(':n derniers jours', ['n' => $days])" icon="fa-chart-pie" accent="slate" :back="route('dashboard')">
            <x-slot name="actions">
                {{-- Sélecteur de période --}}
                <div class="flex items-center gap-1 bg-white rounded-2xl p-1 shadow-sm border border-slate-100">
                    @foreach([7, 30, 90] as $p)
                        <a href="{{ route('dashboard.analytics', ['days' => $p]) }}"
                           @class([
                               'px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all no-underline',
                               'bg-slate-900 text-white' => $days === $p,
                               'text-slate-400 hover:text-slate-700' => $days !== $p,
                           ])>{{ $p }} j</a>
                    @endforeach
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10" x-data="analyticsView({{ Illuminate\Support\Js::from($series) }})">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-8 italic font-bold text-left">

            {{-- KPIs de période --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-rose-50 border border-rose-100 p-6 rounded-[2.5rem] text-center">
                    <p class="text-[8px] font-black text-rose-400 uppercase tracking-widest mb-1">{{ __("Mortalité (cumul période)") }}</p>
                    <p class="text-3xl font-black text-rose-600 leading-none">{{ number_format($totalMortality) }}</p>
                    <p class="text-[8px] text-rose-300 font-black uppercase mt-1">{{ __("têtes") }}</p>
                </div>
                <div class="bg-cyan-50 border border-cyan-100 p-6 rounded-[2.5rem] text-center">
                    <p class="text-[8px] font-black text-cyan-500 uppercase tracking-widest mb-1">{{ __("Eau consommée") }}</p>
                    <p class="text-3xl font-black text-cyan-600 leading-none">{{ number_format($totalWater) }}</p>
                    <p class="text-[8px] text-cyan-300 font-black uppercase mt-1">{{ __("litres") }}</p>
                </div>
                <div class="bg-amber-50 border border-amber-100 p-6 rounded-[2.5rem] text-center">
                    <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-1">{{ __("Coût énergie") }}</p>
                    <p class="text-3xl font-black text-amber-600 leading-none">{{ number_format($totalEnergy) }}</p>
                    <p class="text-[8px] text-amber-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
            </div>

            {{-- Insight de corrélation : jour de mortalité maximale --}}
            @if($peak)
            <div class="bg-slate-900 text-white p-6 rounded-[2.5rem] flex flex-col md:flex-row md:items-center gap-4 not-italic">
                <div class="w-12 h-12 bg-rose-500/20 rounded-2xl flex items-center justify-center text-rose-400 shrink-0">
                    <i class="fa-solid fa-magnifying-glass-chart text-lg"></i>
                </div>
                <div class="text-[11px] font-bold leading-relaxed">
                    <span class="text-rose-400 font-black uppercase tracking-widest text-[9px]">{{ __("Pic de mortalité") }}</span><br>
                    {{ __("Le") }} <span class="font-black text-white">{{ $peak['date'] }}</span> :
                    <span class="text-rose-400 font-black">{{ $peak['mortality'] }} {{ __("morts") }}</span>,
                    {{ __("avec") }} <span class="text-cyan-400 font-black">{{ number_format($peak['water']) }} L</span> {{ __("d'eau et") }}
                    <span class="text-amber-400 font-black">{{ number_format($peak['energy']) }} {{ currency() }}</span> {{ __("d'énergie") }}.
                    <span class="text-slate-400">{{ __("Croisez les 3 courbes ci-dessous : une coupure d'énergie/ventilation ou une chute d'eau le même jour oriente le diagnostic.") }}</span>
                </div>
            </div>
            @endif

            {{-- 3 graphiques ALIGNÉS sur le même axe temps (scan vertical = corrélation) --}}
            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 space-y-6">
                <div>
                    <p class="text-[9px] font-black text-rose-500 uppercase tracking-widest mb-2"><i class="fa-solid fa-skull-crossbones mr-1"></i> {{ __("Mortalité / jour") }}</p>
                    <div class="relative h-32"><canvas x-ref="mortalityChart"></canvas></div>
                </div>
                <div class="pt-4 border-t border-slate-50">
                    <p class="text-[9px] font-black text-cyan-500 uppercase tracking-widest mb-2"><i class="fa-solid fa-droplet mr-1"></i> {{ __("Eau (L) / jour") }}</p>
                    <div class="relative h-32"><canvas x-ref="waterChart"></canvas></div>
                </div>
                <div class="pt-4 border-t border-slate-50">
                    <p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mb-2"><i class="fa-solid fa-bolt mr-1"></i> {{ __("Énergie (:devise) / jour", ['devise' => currency()]) }}</p>
                    <div class="relative h-32"><canvas x-ref="energyChart"></canvas></div>
                </div>
                <p class="text-[8px] text-slate-300 font-black uppercase tracking-widest text-center not-italic">
                    {{ __("Même échelle de temps — un alignement vertical des pics révèle une corrélation") }}
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('analyticsView', (data) => ({
                init() {
                    const ready = () => {
                        if (typeof Chart === 'undefined') { setTimeout(ready, 120); return; }
                        this.draw();
                    };
                    ready();
                },
                draw() {
                    const make = (ref, color, fill, dataset, kind) => {
                        const el = this.$refs[ref];
                        if (!el) return;
                        new Chart(el.getContext('2d'), {
                            type: kind,
                            data: {
                                labels: data.labels,
                                datasets: [{
                                    data: dataset,
                                    borderColor: color,
                                    backgroundColor: fill,
                                    borderWidth: 2,
                                    fill: kind === 'line',
                                    tension: 0.35,
                                    pointRadius: 0,
                                    pointHoverRadius: 4,
                                    borderRadius: kind === 'bar' ? 4 : 0,
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: {
                                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 8 }, color: '#94a3b8' } },
                                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { maxTicksLimit: 4, font: { size: 8 }, color: '#94a3b8' } },
                                },
                            },
                        });
                    };
                    make('mortalityChart', '#f43f5e', 'rgba(244,63,94,0.10)', data.mortality, 'bar');
                    make('waterChart',     '#06b6d4', 'rgba(6,182,212,0.10)',  data.water,     'line');
                    make('energyChart',    '#f59e0b', 'rgba(245,158,11,0.10)', data.energy,    'line');
                },
            }));
        });
    </script>
</x-app-layout>
