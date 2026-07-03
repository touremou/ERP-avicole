<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🧺 Tournée de Collecte')"
                       :subtitle="__('Toutes les bandes pondeuses — une saisie, un enregistrement')"
                       icon="fa-route" accent="emerald"
                       :back="route('egg-productions.index')" />
    </x-slot>

    <div class="py-8 italic font-bold text-left">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="p-4 bg-emerald-50 border-l-4 border-emerald-600 rounded-2xl shadow-sm">
                    <p class="text-sm font-black text-emerald-700">{{ session('success') }}</p>
                </div>
            @endif
            @if (session('error'))
                <div class="p-4 bg-red-50 border-l-4 border-red-600 rounded-2xl shadow-sm">
                    <p class="text-sm font-black text-red-700">{{ session('error') }}</p>
                </div>
            @endif
            @if ($errors->any())
                <div class="p-4 bg-red-50 border-l-4 border-red-600 rounded-2xl shadow-sm">
                    @foreach ($errors->all() as $error)
                        <p class="text-sm font-black text-red-700">{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if($lines->isEmpty())
                <div class="bg-white p-16 rounded-[3rem] border border-slate-100 shadow-sm text-center">
                    <i class="fa-solid fa-egg text-slate-100 text-6xl mb-6"></i>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest">
                        {{ __("Aucune bande pondeuse en âge de ponte actuellement.") }}
                    </p>
                </div>
            @else
                <form action="{{ route('egg-productions.tour.store') }}" method="POST" id="tour-form">
                    @csrf
                    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="text-[8px] font-black uppercase text-slate-400 bg-slate-50/50 border-b border-slate-100 italic">
                                        <th class="px-6 py-5">{{ __("Bande / Bâtiment") }}</th>
                                        <th class="px-4 py-5 text-center">{{ __("Effectif") }}</th>
                                        <th class="px-4 py-5 text-center">{{ __("Hier") }}</th>
                                        <th class="px-4 py-5 text-center">{{ __("Cible") }}</th>
                                        <th class="px-4 py-5 text-center text-emerald-600">{{ __("Alvéoles") }}</th>
                                        <th class="px-4 py-5 text-center text-emerald-600">{{ __("Unités") }}</th>
                                        <th class="px-6 py-5 text-right">{{ __("Taux projeté") }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    @foreach($lines as $i => $line)
                                        @php
                                            $batch   = $line['batch'];
                                            $locked  = $line['quarantined'] || ($line['existing']?->is_graded ?? false);
                                            $already = (int) ($line['existing']?->total_eggs_collected ?? 0);
                                        @endphp
                                        <tr @class(['transition-colors', 'bg-rose-50/40' => $line['quarantined'], 'bg-slate-50/60 opacity-60' => !$line['quarantined'] && $locked, 'hover:bg-emerald-50/30' => !$locked])
                                            data-qty="{{ (int) $batch->current_quantity }}" data-already="{{ $already }}">
                                            <td class="px-6 py-4">
                                                <p class="font-black text-slate-800 uppercase text-sm leading-none">{{ $batch->code }}</p>
                                                <p class="text-[8px] font-black text-blue-400 uppercase tracking-widest mt-1.5">
                                                    <i class="fa-solid fa-location-dot mr-1"></i>{{ $batch->building->name ?? '—' }}
                                                    <span class="text-slate-300 ml-2">S-{{ (int) ceil($batch->age / 7) }}</span>
                                                </p>
                                                @if($line['quarantined'])
                                                    <span class="inline-block mt-2 text-[7px] font-black px-2 py-0.5 rounded uppercase italic bg-rose-600 text-white">
                                                        <i class="fa-solid fa-biohazard mr-0.5"></i>{{ __("Quarantaine — collecte suspendue") }}
                                                    </span>
                                                @elseif($line['existing']?->is_graded)
                                                    <span class="inline-block mt-2 text-[7px] font-black px-2 py-0.5 rounded uppercase italic bg-slate-800 text-white">
                                                        <i class="fa-solid fa-lock mr-0.5"></i>{{ __("Déjà triée — verrouillée") }}
                                                    </span>
                                                @elseif($already > 0)
                                                    <span class="inline-block mt-2 text-[7px] font-black px-2 py-0.5 rounded uppercase italic bg-blue-100 text-blue-600">
                                                        {{ __("Déjà :") }} {{ number_format($already) }} {{ __("œufs — cumul auto") }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-4 text-center font-black text-slate-700">{{ number_format($batch->current_quantity) }}</td>
                                            <td class="px-4 py-4 text-center font-black {{ $line['yesterday_rate'] !== null ? 'text-slate-600' : 'text-slate-200' }}">
                                                {{ $line['yesterday_rate'] !== null ? number_format($line['yesterday_rate'], 1) . ' %' : '—' }}
                                            </td>
                                            <td class="px-4 py-4 text-center font-black {{ $line['target_rate'] > 0 ? 'text-slate-400' : 'text-slate-200' }}">
                                                {{ $line['target_rate'] > 0 ? number_format($line['target_rate'], 0) . ' %' : '—' }}
                                            </td>

                                            @if($locked)
                                                <td colspan="2" class="px-4 py-4 text-center text-[8px] font-black uppercase text-slate-300">{{ __("Saisie désactivée") }}</td>
                                                <td class="px-6 py-4 text-right text-slate-200 font-black">—</td>
                                            @else
                                                <input type="hidden" name="lines[{{ $i }}][batch_id]" value="{{ $batch->id }}">
                                                <td class="px-4 py-4 text-center">
                                                    <input type="number" name="lines[{{ $i }}][trays]" min="0" placeholder="0"
                                                           oninput="tourCalc(this)"
                                                           class="w-20 bg-slate-50 border-none rounded-xl p-3 font-black text-lg text-center shadow-inner focus:bg-white focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                                </td>
                                                <td class="px-4 py-4 text-center">
                                                    <input type="number" name="lines[{{ $i }}][units]" min="0" max="{{ (int) setting('general.eggs_per_tray', 30) - 1 }}" placeholder="0"
                                                           oninput="tourCalc(this)"
                                                           class="w-20 bg-slate-50 border-none rounded-xl p-3 font-black text-lg text-center shadow-inner focus:bg-white focus:ring-2 focus:ring-emerald-500/30 outline-none">
                                                </td>
                                                <td class="px-6 py-4 text-right">
                                                    <span class="tour-rate text-[10px] font-black uppercase text-slate-300">—</span>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-900 text-white">
                                        <td colspan="4" class="px-6 py-5 text-[9px] font-black uppercase tracking-widest italic">
                                            <i class="fa-solid fa-basket-shopping text-emerald-400 mr-2"></i>{{ __("Total de la tournée") }}
                                        </td>
                                        <td colspan="3" class="px-6 py-5 text-right">
                                            <span id="tour-total" class="text-emerald-400 font-black text-xl">0</span>
                                            <span class="text-[9px] font-black uppercase text-slate-400 ml-1">{{ __("œufs") }}</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <button type="submit"
                            class="mt-6 w-full bg-slate-900 text-white font-black py-8 rounded-[2.5rem] shadow-2xl uppercase tracking-[0.3em] text-xs italic transition-all hover:bg-emerald-600 active:scale-[0.99] border-none cursor-pointer">
                        <i class="fa-solid fa-circle-check text-emerald-400 mr-3"></i>{{ __("Enregistrer la tournée") }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    <script>
        const TOUR_EGGS_PER_TRAY = {{ (int) setting('general.eggs_per_tray', 30) ?: 30 }};

        // Recalcule la ligne (taux projeté, cumul du jour inclus) et le total.
        function tourCalc(input) {
            const row  = input.closest('tr');
            const qty  = parseInt(row.dataset.qty || '0', 10);
            const already = parseInt(row.dataset.already || '0', 10);

            const inputs = row.querySelectorAll('input[type=number]');
            const trays  = parseInt(inputs[0]?.value || '0', 10) || 0;
            const units  = parseInt(inputs[1]?.value || '0', 10) || 0;
            const total  = trays * TOUR_EGGS_PER_TRAY + units;

            const badge = row.querySelector('.tour-rate');
            if (badge) {
                if (total <= 0 || qty <= 0) {
                    badge.textContent = '—';
                    badge.className = 'tour-rate text-[10px] font-black uppercase text-slate-300';
                } else {
                    const rate = ((already + total) / qty) * 100;
                    badge.textContent = rate.toFixed(1) + ' %';
                    badge.className = 'tour-rate text-[10px] font-black uppercase ' + (
                        rate > 100 ? 'text-red-600' : (rate > 85 ? 'text-amber-500' : 'text-emerald-600')
                    );
                }
            }

            let grand = 0;
            document.querySelectorAll('#tour-form tbody tr').forEach(r => {
                const ins = r.querySelectorAll('input[type=number]');
                if (ins.length === 2) {
                    grand += (parseInt(ins[0].value || '0', 10) || 0) * TOUR_EGGS_PER_TRAY
                           + (parseInt(ins[1].value || '0', 10) || 0);
                }
            });
            const grandEl = document.getElementById('tour-total');
            if (grandEl) grandEl.textContent = grand.toLocaleString();
        }
    </script>
</x-app-layout>
