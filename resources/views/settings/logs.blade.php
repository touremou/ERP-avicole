<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Journal Système')" :subtitle="__('Historique des modifications de paramètres')" icon="fa-clock-rotate-left" accent="slate" />
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            {{-- FILTRES --}}
            <form method="GET" action="{{ route('settings.logs') }}" class="mb-6 bg-white p-5 rounded-2xl border border-slate-100 shadow-sm">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[120px]">
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Module") }}</label>
                        <select name="group" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                            <option value="">{{ __("Tous") }}</option>
                            @foreach($groups as $gKey => $g)
                                <option value="{{ $gKey }}" {{ request('group') === $gKey ? 'selected' : '' }}>{{ $g['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 min-w-[120px]">
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Utilisateur") }}</label>
                        <select name="user" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                            <option value="">{{ __("Tous") }}</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ request('user') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-[130px]">
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Du") }}</label>
                        <input type="date" name="from" value="{{ request('from') }}" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                    </div>
                    <div class="min-w-[130px]">
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Au") }}</label>
                        <input type="date" name="to" value="{{ request('to') }}" class="w-full bg-slate-50 border-none rounded-xl p-2.5 text-[10px] font-black shadow-inner outline-none">
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-blue-600 border-none cursor-pointer shadow-lg italic">
                        <i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}
                    </button>
                    @if(request()->hasAny(['group', 'user', 'from', 'to']))
                    <a href="{{ route('settings.logs') }}" class="text-[9px] font-black text-slate-400 hover:text-red-500 no-underline uppercase tracking-widest px-3 py-2.5">
                        <i class="fa-solid fa-xmark mr-1"></i> {{ __("Reset") }}
                    </a>
                    @endif
                </div>
            </form>

            {{-- TABLE DES LOGS --}}
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden text-left">
                <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="text-[9px] font-black uppercase tracking-widest text-slate-400 flex items-center gap-2">
                        <i class="fa-solid fa-clock-rotate-left text-blue-500"></i> {{ __(":count entrée(s)", ['count' => $audits->total()]) }}
                    </h3>
                </div>

                <table class="w-full border-collapse">
                    <thead>
                        <tr class="text-[7px] font-black text-slate-400 uppercase tracking-widest bg-slate-50/50 border-b border-slate-100">
                            <th class="px-5 py-3 text-left">{{ __("Date") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Utilisateur") }}</th>
                            <th class="px-3 py-3 text-left">{{ __("Paramètre") }}</th>
                            <th class="px-3 py-3 text-center">{{ __("Avant") }}</th>
                            <th class="px-3 py-3 text-center">{{ __("Après") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($audits as $a)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-5 py-3">
                                <p class="text-[10px] font-black text-slate-700">{{ \Carbon\Carbon::parse($a->created_at)->format('d/m/Y') }}</p>
                                <p class="text-[8px] text-slate-400">{{ \Carbon\Carbon::parse($a->created_at)->format('H:i:s') }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-md bg-slate-100 flex items-center justify-center text-[8px] font-black text-slate-400 shrink-0">
                                        {{ strtoupper(substr($a->user_name, 0, 1)) }}
                                    </span>
                                    <span class="text-[10px] font-black text-slate-600">{{ $a->user_name }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                @php
                                    $groupMeta = $groups[$a->group] ?? ['label' => $a->group, 'color' => 'slate', 'icon' => 'fa-cog'];
                                @endphp
                                <span class="text-[8px] font-black text-{{ $groupMeta['color'] }}-500 uppercase">
                                    <i class="fa-solid {{ $groupMeta['icon'] }} mr-0.5"></i> {{ $groupMeta['label'] }}
                                </span>
                                <p class="text-[10px] font-black text-slate-800 mt-0.5">{{ $a->key }}</p>
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if($a->old_value === '***')
                                    <span class="text-[9px] text-slate-300">•••</span>
                                @elseif($a->old_value)
                                    <span class="text-[10px] font-black text-red-400 bg-red-50 px-2 py-0.5 rounded-lg line-through">{{ \Illuminate\Support\Str::limit($a->old_value, 30) }}</span>
                                @else
                                    <span class="text-[9px] text-slate-300 italic">{{ __("vide") }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if($a->new_value === '***')
                                    <span class="text-[9px] text-slate-300">•••</span>
                                @elseif($a->new_value)
                                    <span class="text-[10px] font-black text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-lg">{{ \Illuminate\Support\Str::limit($a->new_value, 30) }}</span>
                                @else
                                    <span class="text-[9px] text-slate-300 italic">{{ __("vide") }}</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-8 py-12 text-center text-slate-300 text-[9px] uppercase italic tracking-widest">{{ __("Aucune modification enregistrée") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="px-6 py-3 border-t border-slate-50">{{ $audits->withQueryString()->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
