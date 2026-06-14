<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <a href="{{ route('notifications.preferences') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Historique Notifications") }}</h2>
                <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ __("Envois WhatsApp — diagnostic des échecs") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            {{-- STATS --}}
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Envoyés (jour)") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $stats['today_sent'] }}</p>
                </div>
                <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-red-400 uppercase tracking-widest mb-1">{{ __("Échoués (jour)") }}</p>
                    <p class="text-2xl font-black {{ $stats['today_failed'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $stats['today_failed'] }}</p>
                </div>
                <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-blue-400 uppercase tracking-widest mb-1">{{ __("Total") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $stats['total'] }}</p>
                </div>
            </div>

            {{-- FILTRES --}}
            <form method="GET" action="{{ route('notifications.logs') }}" class="mb-6 bg-white p-5 rounded-2xl border border-slate-100 shadow-sm">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[140px]">
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Statut") }}</label>
                        <select name="status" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                            <option value="">{{ __("Tous") }}</option>
                            @foreach(['sent' => __('Envoyé'), 'failed' => __('Échoué'), 'queued' => __('En attente')] as $val => $label)
                                <option value="{{ $val }}" {{ request('status') === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 min-w-[160px]">
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Type") }}</label>
                        <input type="text" name="type" value="{{ request('type') }}" placeholder="alert_stock, test..."
                            class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-blue-600 border-none cursor-pointer shadow-lg italic">
                        <i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}
                    </button>
                    @if(request()->hasAny(['status', 'type']))
                    <a href="{{ route('notifications.logs') }}" class="text-[9px] font-black text-slate-400 hover:text-red-500 no-underline uppercase tracking-widest px-3 py-2.5">
                        <i class="fa-solid fa-xmark mr-1"></i> {{ __("Reset") }}
                    </a>
                    @endif
                </div>
            </form>

            {{-- TABLE DES LOGS --}}
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden text-left">
                <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="text-[9px] font-black uppercase tracking-widest text-slate-400 flex items-center gap-2">
                        <i class="fa-brands fa-whatsapp text-emerald-500"></i> {{ __(":count entrée(s)", ['count' => $logs->total()]) }}
                    </h3>
                </div>

                <table class="w-full border-collapse">
                    <thead>
                        <tr class="text-[7px] font-black text-slate-400 uppercase tracking-widest bg-slate-50/50 border-b border-slate-100">
                            <th class="px-5 py-3 text-left">{{ __("Date") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Destinataire") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Type") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Titre") }}</th>
                            <th class="px-3 py-3 text-center">{{ __("Tentatives") }}</th>
                            <th class="px-3 py-3 text-center">{{ __("Statut") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Détail provider") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($logs as $log)
                        <tr class="hover:bg-slate-50/50 transition-all align-top">
                            <td class="px-5 py-3 whitespace-nowrap">
                                <p class="text-[10px] font-black text-slate-700">{{ $log->created_at->format('d/m/Y') }}</p>
                                <p class="text-[8px] text-slate-400">{{ $log->created_at->format('H:i:s') }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <p class="text-[10px] font-black text-slate-700">{{ $log->recipient_phone ?? '—' }}</p>
                                @if($log->user)
                                    <p class="text-[8px] text-slate-400">{{ $log->user->name }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <span class="text-[8px] font-black text-slate-500 uppercase">{{ $log->type }}</span>
                            </td>
                            <td class="px-3 py-3">
                                <p class="text-[10px] font-black text-slate-700">{{ $log->title }}</p>
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span class="text-[10px] font-black {{ $log->attempts > 1 ? 'text-amber-600' : 'text-slate-400' }}">{{ $log->attempts }}</span>
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span @class([
                                    'text-[8px] font-black uppercase px-2 py-1 rounded-full whitespace-nowrap',
                                    'bg-emerald-50 text-emerald-600' => $log->status === 'sent',
                                    'bg-red-50 text-red-600' => $log->status === 'failed',
                                    'bg-amber-50 text-amber-600' => $log->status === 'queued',
                                ])>{{ $log->status }}</span>
                            </td>
                            <td class="px-3 py-3 max-w-xs">
                                @if($log->status === 'failed' && $log->provider_response)
                                    @php
                                        $resp = $log->provider_response;
                                        $detail = $resp['error'] ?? (($resp['status'] ?? '') . ' — ' . ($resp['body'] ?? ''));
                                    @endphp
                                    <p class="text-[8px] text-red-500 normal-case break-words">{{ \Illuminate\Support\Str::limit($detail, 200) }}</p>
                                @elseif($log->status === 'sent')
                                    <span class="text-[8px] text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="px-8 py-12 text-center text-slate-300 text-[9px] uppercase italic tracking-widest">{{ __("Aucune notification enregistrée") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="px-6 py-3 border-t border-slate-50">{{ $logs->withQueryString()->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
