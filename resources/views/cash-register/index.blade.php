<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    🧰 {{ __("Session de caisse") }}
                </h2>
                <p class="text-[10px] font-black text-teal-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ __("Ouverture · comptage · écart") }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('commerce.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-table-columns"></i> {{ __("Tableau de bord") }}
                </a>
                <a href="{{ route('pos.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-cash-register"></i> {{ __("Caisse (POS)") }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'triangle-exclamation' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            @if($open)
                {{-- SESSION OUVERTE → clôture avec comptage --}}
                <div class="bg-slate-900 text-white p-6 rounded-[2.5rem] shadow-2xl"
                     x-data="closeRegister({{ (float) $expectedNow }})">
                    <div class="flex justify-between items-start mb-5 not-italic">
                        <div>
                            <p class="text-[9px] font-black uppercase tracking-widest text-teal-400">{{ __("Caisse ouverte") }}</p>
                            <p class="text-[10px] text-slate-400 mt-1">{{ __("Par") }} {{ $open->user?->name ?? '—' }} · {{ $open->opened_at->format('d/m/Y H:i') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[8px] font-black uppercase tracking-widest text-slate-500">{{ __("Fond d'ouverture") }}</p>
                            <p class="text-lg font-black">{{ number_format($open->opening_float, 0, ',', ' ') }} {{ currency() }}</p>
                        </div>
                    </div>

                    <div class="bg-white/5 rounded-2xl p-4 mb-5 flex justify-between items-center border border-white/10">
                        <span class="text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Espèces théoriques en caisse") }}</span>
                        <span class="text-xl font-black text-teal-400">{{ number_format($expectedNow, 0, ',', ' ') }} {{ currency() }}</span>
                    </div>

                    <form method="POST" action="{{ route('cash-register.close', $open) }}">
                        @csrf
                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-500 mb-3">{{ __("Comptage des billets") }}</p>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-5">
                            @foreach($denominations as $d)
                            <div class="bg-white/5 rounded-xl p-3 border border-white/10">
                                <label class="block text-[9px] font-black text-slate-300 mb-1">{{ number_format($d, 0, ',', ' ') }} {{ currency() }}</label>
                                <input type="number" name="counts[{{ $d }}]" min="0" value="0" @input="recount()" data-denom="{{ $d }}"
                                       class="w-full bg-white/10 rounded-lg p-2 text-center text-sm font-black outline-none border-none text-white denom-input">
                            </div>
                            @endforeach
                        </div>

                        <div class="space-y-2 mb-5 not-italic">
                            <div class="flex justify-between text-[10px] font-black"><span class="text-slate-400 uppercase">{{ __("Compté") }}</span><span x-text="fmt(counted)"></span></div>
                            <div class="flex justify-between text-[10px] font-black"><span class="text-slate-400 uppercase">{{ __("Théorique") }}</span><span>{{ number_format($expectedNow, 0, ',', ' ') }} {{ currency() }}</span></div>
                            <div class="flex justify-between text-sm font-black border-t border-white/10 pt-2">
                                <span class="uppercase">{{ __("Écart") }}</span>
                                <span :class="diff === 0 ? 'text-emerald-400' : (diff > 0 ? 'text-amber-400' : 'text-red-400')" x-text="(diff > 0 ? '+' : '') + fmt(diff)"></span>
                            </div>
                        </div>

                        <input type="text" name="notes" placeholder="{{ __('Note de clôture (optionnel)') }}" class="w-full bg-white/5 border border-white/10 rounded-2xl p-3 text-[10px] font-black outline-none mb-4 text-white uppercase italic">
                        <button type="submit" class="w-full bg-teal-500 text-white font-black py-5 rounded-[2rem] hover:bg-teal-400 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                            <i class="fa-solid fa-lock mr-2"></i> {{ __("Clôturer la caisse") }}
                        </button>
                    </form>
                </div>
            @else
                {{-- AUCUNE SESSION → ouverture --}}
                @can('commerce.C')
                <form method="POST" action="{{ route('cash-register.open') }}" class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    @csrf
                    <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-4"><i class="fa-solid fa-unlock mr-1"></i> {{ __("Ouvrir la caisse") }}</h3>
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">{{ __("Fond de caisse (espèces en début de journée)") }}</label>
                    <div class="flex gap-3">
                        <input type="number" name="opening_float" min="0" step="1" value="0" required class="flex-1 bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-slate-800 shadow-inner outline-none text-right">
                        <button type="submit" class="bg-slate-900 text-white px-6 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-teal-600 transition-all border-none cursor-pointer">{{ __("Ouvrir") }}</button>
                    </div>
                </form>
                @endcan
            @endif

            {{-- HISTORIQUE --}}
            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <p class="px-6 pt-5 text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Historique des clôtures") }}</p>
                <table class="w-full border-collapse mt-3">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-3 text-left">{{ __("Clôture") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Caissier") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Théorique") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Compté") }}</th>
                            <th class="px-6 py-3 text-right">{{ __("Écart") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($history as $s)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-3 text-[10px] font-black text-slate-600">{{ $s->closed_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-500 uppercase">{{ $s->user?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-slate-500">{{ number_format($s->expected_cash, 0, ',', ' ') }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-slate-700">{{ number_format($s->counted_cash, 0, ',', ' ') }}</td>
                            <td class="px-6 py-3 text-right text-[11px] font-black {{ $s->difference == 0 ? 'text-emerald-600' : ($s->difference > 0 ? 'text-amber-600' : 'text-red-600') }}">
                                {{ $s->difference > 0 ? '+' : '' }}{{ number_format($s->difference, 0, ',', ' ') }}
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="p-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucune clôture.") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $history->links() }}</div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('closeRegister', (expected) => ({
                counted: 0,
                expected,
                get diff() { return this.counted - this.expected; },
                recount() {
                    let total = 0;
                    document.querySelectorAll('.denom-input').forEach(el => {
                        total += (parseInt(el.dataset.denom) || 0) * (parseInt(el.value) || 0);
                    });
                    this.counted = total;
                },
                fmt(v) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Math.round(v || 0)) + ' {{ currency() }}'; },
            }));
        });
    </script>
</x-app-layout>
