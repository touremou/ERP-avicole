<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Registre des températures')" :subtitle="__('E4 — Relevés manuels par point de contrôle')" icon="fa-temperature-half" accent="rose">
            <x-slot name="actions">
                <a href="{{ route('slaughter.registres.export', array_filter(['type' => 'temperatures', 'from' => request('from'), 'to' => request('to')])) }}" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-slate-700 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-file-pdf mr-1"></i> {{ __("Export PDF") }}</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="tempForm()" x-cloak>

            <x-flash />

            @if($errors->any())
                <div class="mb-6 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
            @endif

            {{-- RELEVÉS DU JOUR : X / N requis par point --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                @foreach(\App\Models\TemperatureLog::POINT_LABELS as $pt => $label)
                    @php $count = $todayCounts[$pt] ?? 0; @endphp
                    <div @class(['p-4 rounded-[2rem] border shadow-sm text-center',
                        'bg-emerald-50 border-emerald-200' => $count >= $requiredPerDay,
                        'bg-white border-slate-100'        => $count < $requiredPerDay])>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __($label) }}</p>
                        <p class="text-xl font-black {{ $count >= $requiredPerDay ? 'text-emerald-600' : 'text-slate-900' }}">{{ $count }} <small class="text-[9px] text-slate-400">/ {{ $requiredPerDay }}</small></p>
                        <p class="text-[7px] text-slate-400 uppercase">{{ __("relevés du jour") }}</p>
                    </div>
                @endforeach
            </div>

            {{-- SAISIE EN TOURNÉE : tous les points en une validation (lignes vides ignorées). --}}
            @can('abattoir.C')
            <form method="POST" action="{{ route('slaughter.registres.temperatures.batch') }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-8">
                @csrf
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-1"><i class="fa-solid fa-route mr-1"></i> {{ __("Tournée de températures") }}</p>
                <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest mb-4">{{ __("Remplissez les points relevés, laissez vides les autres — une seule validation.") }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="px-2 py-2 text-left">{{ __("Point de contrôle") }}</th>
                                <th class="px-2 py-2 text-left">{{ __("Bornes") }}</th>
                                <th class="px-2 py-2 text-center">{{ __("Aujourd'hui") }}</th>
                                <th class="px-2 py-2 text-left">{{ __("Équipement") }}</th>
                                <th class="px-2 py-2 text-center">{{ __("T° (°C)") }}</th>
                                <th class="px-2 py-2 text-left">{{ __("Action corrective") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach(\App\Models\TemperatureLog::POINT_LABELS as $pt => $label)
                            @php $b = \App\Models\TemperatureLog::boundsFor($pt); $done = (int) ($todayCounts[$pt] ?? 0); @endphp
                            <tr>
                                <td class="px-2 py-2 text-[10px] font-black text-slate-700 uppercase whitespace-nowrap">{{ __($label) }}</td>
                                <td class="px-2 py-2 text-[9px] font-black text-blue-500 whitespace-nowrap">{{ ($b['min'] !== null ? 'min '.$b['min'].'° ' : '') . ($b['max'] !== null ? 'max '.$b['max'].'°' : '—') }}</td>
                                <td class="px-2 py-2 text-center">
                                    <span class="text-[9px] font-black px-2 py-0.5 rounded-full {{ $done >= $requiredPerDay ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' }}">{{ $done }}/{{ $requiredPerDay }}</span>
                                </td>
                                <td class="px-2 py-2"><input type="text" name="rows[{{ $pt }}][equipment_ref]" value="{{ old("rows.{$pt}.equipment_ref", $lastEquipments[$pt] ?? '') }}" maxlength="50" placeholder="CF-01…" class="w-24 bg-slate-50 border-none rounded-xl p-2 text-[10px] font-black shadow-inner outline-none"></td>
                                <td class="px-2 py-2 text-center"><input type="number" name="rows[{{ $pt }}][temperature]" value="{{ old("rows.{$pt}.temperature") }}" step="0.1" min="-60" max="120" class="w-24 bg-slate-50 border-none rounded-xl p-2 text-base font-black shadow-inner outline-none text-center"></td>
                                <td class="px-2 py-2"><input type="text" name="rows[{{ $pt }}][corrective_action]" value="{{ old("rows.{$pt}.corrective_action") }}" maxlength="2000" placeholder="{{ __('Si hors seuil…') }}" class="w-full bg-slate-50 border-none rounded-xl p-2 text-[10px] font-bold shadow-inner outline-none"></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="mt-4 w-full bg-rose-500 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-[0.2em] hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-route mr-2"></i> {{ __("Valider la tournée") }}</button>
            </form>
            @endcan

            {{-- SAISIE RAPIDE (relevé isolé) --}}
            @can('abattoir.C')
            <form method="POST" action="{{ route('slaughter.registres.temperatures.store') }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-8">
                @csrf
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-4"><i class="fa-solid fa-bolt mr-1"></i> {{ __("Saisie rapide") }}</p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Point de contrôle *") }}</label>
                        <select name="point" x-model="point" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("— Sélectionner —") }}</option>
                            @foreach(\App\Models\TemperatureLog::POINT_LABELS as $pt => $label)
                                @php $b = \App\Models\TemperatureLog::boundsFor($pt); @endphp
                                <option value="{{ $pt }}" data-bounds="{{ ($b['min'] !== null ? 'min '.$b['min'].'°C ' : '') . ($b['max'] !== null ? 'max '.$b['max'].'°C' : '') }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <p class="text-[8px] text-blue-500 font-black ml-2" x-show="bounds" x-text="'{{ __('Bornes :') }} ' + bounds"></p>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Équipement") }}</label>
                        <input type="text" name="equipment_ref" maxlength="50" placeholder="CF-01..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Température (°C) *") }}</label>
                        <input type="number" name="temperature" step="0.1" min="-60" max="120" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Action corrective") }}</label>
                        <input type="text" name="corrective_action" maxlength="2000" placeholder="{{ __('Si hors seuil...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">
                    </div>
                    <button type="submit" class="bg-rose-500 text-white p-4 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-plus mr-1"></i> {{ __("Enregistrer") }}</button>
                </div>
            </form>
            @endcan

            {{-- FILTRES --}}
            <form method="GET" class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm mb-6 grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Point") }}</label>
                    <select name="point" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                        <option value="">{{ __("Tous") }}</option>
                        @foreach(\App\Models\TemperatureLog::POINT_LABELS as $pt => $label)
                            <option value="{{ $pt }}" @selected(request('point') === $pt)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Du") }}</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Au") }}</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                </div>
                <button type="submit" class="bg-slate-900 text-white p-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-filter mr-1"></i> {{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-5 py-4 text-left">{{ __("Point de contrôle") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Équipement") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Température") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Conforme") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Action corrective") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Opérateur") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Relevé le") }}</th>
                            <th class="px-5 py-4 text-center">{{ __("Synchronisé le") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($logs as $log)
                        <tr @class(['hover:bg-slate-50/50 transition-all', 'bg-red-50/30' => ! $log->conforme])>
                            <td class="px-5 py-4 text-[10px] font-black text-slate-800 uppercase">{{ __(\App\Models\TemperatureLog::POINT_LABELS[$log->point] ?? $log->point) }}</td>
                            <td class="px-3 py-4 text-[9px] font-black text-slate-500">{{ $log->equipment_ref ?: '—' }}</td>
                            <td class="px-3 py-4 text-center text-sm font-black {{ $log->conforme ? 'text-slate-900' : 'text-red-600' }}">{{ number_format($log->temperature, 1) }} °C</td>
                            <td class="px-3 py-4 text-center">
                                @if($log->conforme)
                                    <span class="text-[8px] font-black text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full uppercase">{{ __("Conforme") }}</span>
                                @else
                                    <span class="text-[8px] font-black text-red-700 bg-red-100 px-2 py-1 rounded-full uppercase animate-pulse">{{ __("HORS SEUIL") }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-4 text-[9px] text-slate-500 font-bold max-w-[200px]">{{ $log->corrective_action ?: '—' }}</td>
                            <td class="px-3 py-4 text-[9px] font-black text-slate-600 uppercase">{{ $log->operator?->name ?? '—' }}</td>
                            <td class="px-3 py-4 text-center text-[9px] font-black text-slate-600">{{ $log->releve_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-5 py-4 text-center text-[9px] font-black text-slate-400">{{ $log->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-temperature-half text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucun relevé de température") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-6">{{ $logs->links() }}</div>
        </div>
    </div>

    <script>
    function tempForm() {
        return {
            point: '',
            get bounds() {
                const sel = document.querySelector('select[name="point"][x-model="point"]');
                if (!sel || !this.point) return '';
                const opt = sel.options[sel.selectedIndex];
                return opt ? (opt.dataset.bounds || '') : '';
            },
        }
    }
    </script>
</x-app-layout>
