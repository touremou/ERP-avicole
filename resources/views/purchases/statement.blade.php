<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('purchases.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline shrink-0">
                    <i class="fa-solid fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">📄 {{ __("Relevé fournisseur") }}</h2>
                    <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest mt-1 italic leading-none">{{ $provider->name }} · {{ $provider->provider_id }}</p>
                </div>
            </div>
            <a href="{{ route('purchases.statement.pdf', $provider) }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-rose-50 hover:text-rose-600 transition-all no-underline shadow-sm italic">
                <i class="fa-solid fa-file-pdf"></i> {{ __("PDF") }}
            </a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Total acheté") }}</p>
                    <p class="text-xl font-black text-slate-800 leading-none">{{ number_format($statement['total_debit'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Total réglé (net)") }}</p>
                    <p class="text-xl font-black text-emerald-600 leading-none">{{ number_format($statement['total_credit'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Dette en cours") }}</p>
                    <p class="text-xl font-black {{ $statement['balance'] > 0 ? 'text-rose-600' : 'text-slate-800' }} leading-none">{{ number_format($statement['balance'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
            </div>

            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <p class="px-6 pt-5 text-[9px] font-black uppercase tracking-widest text-slate-400">{{ __("Mouvements du compte") }}</p>
                <table class="w-full border-collapse mt-3">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-3 text-left">{{ __("Date") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Libellé") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Débit") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Crédit") }}</th>
                            <th class="px-6 py-3 text-right">{{ __("Solde") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($statement['rows'] as $r)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-6 py-3 text-[10px] font-black text-slate-400 whitespace-nowrap">{{ $r['date']->format('d/m/Y') }}</td>
                            <td class="px-3 py-3 text-[10px] font-black text-slate-600">
                                <span @class([
                                    'inline-block w-1.5 h-1.5 rounded-full mr-1.5 align-middle',
                                    'bg-slate-400' => $r['type'] === 'achat',
                                    'bg-emerald-500' => $r['type'] === 'reglement',
                                    'bg-rose-500' => $r['type'] === 'avoir',
                                ])></span>{{ $r['label'] }}
                            </td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-slate-700">{{ $r['debit'] ? number_format($r['debit'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black {{ $r['credit'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $r['credit'] ? number_format($r['credit'], 0, ',', ' ') : '—' }}</td>
                            <td class="px-6 py-3 text-right text-[11px] font-black {{ $r['balance'] > 0 ? 'text-slate-900' : 'text-slate-400' }}">{{ number_format($r['balance'], 0, ',', ' ') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-6 py-10 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun mouvement.") }}</td></tr>
                        @endforelse
                    </tbody>
                    @if($statement['rows']->isNotEmpty())
                    <tfoot>
                        <tr class="bg-slate-900 text-white">
                            <td class="px-6 py-4 text-[9px] font-black uppercase tracking-widest" colspan="2">{{ __("Dette en cours") }}</td>
                            <td class="px-3 py-4 text-right text-[10px] font-black text-slate-400">{{ number_format($statement['total_debit'], 0, ',', ' ') }}</td>
                            <td class="px-3 py-4 text-right text-[10px] font-black text-slate-400">{{ number_format($statement['total_credit'], 0, ',', ' ') }}</td>
                            <td class="px-6 py-4 text-right text-sm font-black {{ $statement['balance'] > 0 ? 'text-rose-400' : 'text-emerald-400' }}">{{ number_format($statement['balance'], 0, ',', ' ') }} {{ currency() }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>

            <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest text-center not-italic">
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-slate-400 mr-1 align-middle"></span> {{ __("Achat (débit)") }}
                &nbsp;·&nbsp;
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1 align-middle"></span> {{ __("Règlement (crédit)") }}
                &nbsp;·&nbsp;
                <span class="inline-block w-1.5 h-1.5 rounded-full bg-rose-500 mr-1 align-middle"></span> {{ __("Avoir fournisseur") }}
            </p>
        </div>
    </div>
</x-app-layout>
