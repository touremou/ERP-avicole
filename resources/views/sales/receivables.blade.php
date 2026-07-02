<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Recouvrement')" :subtitle="__('Encours clients en retard de paiement')" icon="fa-hand-holding-dollar" accent="teal">
            <x-slot name="actions">
                <div class="bg-white rounded-2xl border border-slate-100 shadow-sm px-6 py-3 text-right">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __('Total en retard') }}</p>
                    <p class="text-lg font-black text-rose-600">{{ number_format($totalDue, 0, ',', ' ') }} {{ currency() }}</p>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 text-left">

            <x-flash />

            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-4 text-left">{{ __('Client') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('Référence') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Reste dû') }}</th>
                            <th class="px-6 py-4 text-center">{{ __('Retard') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('Dernière relance') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 text-[11px] font-bold">
                        @forelse($overdue as $sale)
                            @php $last = $sale->reminders->first(); @endphp
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-4 font-black text-slate-800 uppercase">{{ $sale->client?->name ?? '—' }}</td>
                                <td class="px-6 py-4"><a href="{{ route('sales.show', $sale) }}" class="text-blue-600 no-underline hover:underline">{{ $sale->reference }}</a></td>
                                <td class="px-6 py-4 text-right font-black text-rose-600">{{ number_format($sale->remaining_amount, 0, ',', ' ') }}</td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2 py-1 rounded-lg text-[8px] font-black uppercase {{ $sale->days_overdue > 30 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' }}">{{ $sale->days_overdue }} {{ __('j') }}</span>
                                </td>
                                <td class="px-6 py-4 text-slate-500">{{ $last?->sent_at?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    @can('commerce.M')
                                    <form action="{{ route('sales.receivables.remind', $sale) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-[9px] font-black text-emerald-600 uppercase tracking-widest hover:text-emerald-800 bg-transparent border-none cursor-pointer">
                                            <i class="fa-brands fa-whatsapp mr-1"></i>{{ __('Relancer') }}
                                        </button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-12 text-center text-emerald-400 font-black uppercase text-[10px] tracking-widest italic"><i class="fa-solid fa-circle-check mr-1"></i>{{ __('Aucun encours en retard') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
