<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Registre nettoyage & désinfection')" :subtitle="__('E7 — Exécution du plan de nettoyage')" icon="fa-broom" accent="rose">
            <x-slot name="actions">
                <a href="{{ route('slaughter.registres.export', array_filter(['type' => 'nettoyage', 'from' => request('from'), 'to' => request('to')])) }}" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-slate-700 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-file-pdf mr-1"></i> {{ __("Export PDF") }}</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            @if($errors->any())
                <div class="mb-6 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
            @endif

            {{-- TOURNÉE DE NETTOYAGE : toutes les zones cochées en une validation. --}}
            @can('abattoir.C')
            <form method="POST" action="{{ route('slaughter.registres.nettoyage.batch') }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-8">
                @csrf
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-1"><i class="fa-solid fa-route mr-1"></i> {{ __("Tournée de nettoyage") }}</p>
                <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest mb-4">{{ __("Cochez les zones nettoyées — produit/dosage rappelés de la dernière tournée.") }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="px-2 py-2 text-center">{{ __("Fait") }}</th>
                                <th class="px-2 py-2 text-left">{{ __("Zone") }}</th>
                                <th class="px-2 py-2 text-left">{{ __("Produit utilisé") }}</th>
                                <th class="px-2 py-2 text-left">{{ __("Dosage") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach(\App\Models\CleaningLog::ZONES as $key => $label)
                            @if($key !== 'autre')
                            @php $last = $lastByZone[$key] ?? null; @endphp
                            <tr>
                                <td class="px-2 py-2 text-center">
                                    <input type="hidden" name="rows[{{ $key }}][done]" value="0">
                                    <input type="checkbox" name="rows[{{ $key }}][done]" value="1" @checked(old("rows.{$key}.done")) class="w-5 h-5 rounded">
                                </td>
                                <td class="px-2 py-2 text-[10px] font-black text-slate-700 uppercase whitespace-nowrap">{{ __($label) }}</td>
                                <td class="px-2 py-2"><input type="text" name="rows[{{ $key }}][product_used]" value="{{ old("rows.{$key}.product_used", $last->product_used ?? '') }}" maxlength="100" placeholder="{{ __('Produit agréé...') }}" class="w-full bg-slate-50 border-none rounded-xl p-2 text-[10px] font-black shadow-inner outline-none"></td>
                                <td class="px-2 py-2"><input type="text" name="rows[{{ $key }}][dosage]" value="{{ old("rows.{$key}.dosage", $last->dosage ?? '') }}" maxlength="50" placeholder="2%…" class="w-24 bg-slate-50 border-none rounded-xl p-2 text-[10px] font-black shadow-inner outline-none"></td>
                            </tr>
                            @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="mt-4 w-full bg-rose-500 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-[0.2em] hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-route mr-2"></i> {{ __("Valider la tournée") }}</button>
            </form>
            @endcan

            {{-- SAISIE RAPIDE (opération isolée, avec photo/notes) --}}
            @can('abattoir.C')
            <form method="POST" action="{{ route('slaughter.registres.nettoyage.store') }}" enctype="multipart/form-data" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-8">
                @csrf
                <p class="text-[10px] font-black uppercase tracking-widest text-rose-600 mb-4"><i class="fa-solid fa-broom mr-1"></i> {{ __("Enregistrer une opération") }}</p>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Zone *") }}</label>
                        <select name="zone" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("— Sélectionner —") }}</option>
                            @foreach(\App\Models\CleaningLog::ZONES as $key => $label)
                                <option value="{{ $key }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Produit utilisé *") }}</label>
                        <input type="text" name="product_used" maxlength="100" required placeholder="{{ __('Produit agréé...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Dosage") }}</label>
                        <input type="text" name="dosage" maxlength="50" placeholder="2%..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Notes") }}</label>
                        <input type="text" name="notes" maxlength="1000" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Photo") }}</label>
                        <input type="file" name="photo" accept="image/jpeg,image/png" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-[10px] font-bold shadow-inner outline-none">
                    </div>
                    <button type="submit" class="bg-rose-500 text-white p-4 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-plus mr-1"></i> {{ __("Enregistrer") }}</button>
                </div>
            </form>
            @endcan

            {{-- FILTRES --}}
            <form method="GET" class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm mb-6 grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
                <div class="space-y-1">
                    <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Zone") }}</label>
                    <select name="zone" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                        <option value="">{{ __("Toutes") }}</option>
                        @foreach(\App\Models\CleaningLog::ZONES as $key => $label)
                            <option value="{{ $key }}" @selected(request('zone') === $key)>{{ __($label) }}</option>
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
                            <th class="px-5 py-4 text-left">{{ __("Zone") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Produit") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Dosage") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Notes") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Photo") }}</th>
                            <th class="px-3 py-4 text-left">{{ __("Opérateur") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Effectué le") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Synchronisé le") }}</th>
                            @can('abattoir.C')<th class="px-5 py-4 text-center">{{ __("Refaire") }}</th>@endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($logs as $log)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-5 py-4 text-[10px] font-black text-slate-800 uppercase">{{ __(\App\Models\CleaningLog::ZONES[$log->zone] ?? $log->zone) }}</td>
                            <td class="px-3 py-4 text-[10px] font-black text-slate-700">{{ $log->product_used }}</td>
                            <td class="px-3 py-4 text-center text-[9px] font-black text-slate-500">{{ $log->dosage ?: '—' }}</td>
                            <td class="px-3 py-4 text-[9px] text-slate-500 font-bold max-w-[200px]">{{ $log->notes ?: '—' }}</td>
                            <td class="px-3 py-4 text-center">
                                @if($log->photo_path)
                                    <a href="{{ media_url($log->photo_path) }}" target="_blank" class="text-blue-500 hover:text-blue-700 no-underline" title="{{ __('Voir la photo') }}"><i class="fa-solid fa-image"></i></a>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-4 text-[9px] font-black text-slate-600 uppercase">{{ $log->operator?->name ?? '—' }}</td>
                            <td class="px-3 py-4 text-center text-[9px] font-black text-slate-600">{{ $log->done_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-3 py-4 text-center text-[9px] font-black text-slate-400">{{ $log->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            @can('abattoir.C')
                            <td class="px-5 py-4 text-center">
                                {{-- Anti-corvée : re-consigner l'opération identique
                                     (zone/produit/dosage) en UN clic, horodatée maintenant. --}}
                                <form method="POST" action="{{ route('slaughter.registres.nettoyage.store') }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="zone" value="{{ $log->zone }}">
                                    <input type="hidden" name="product_used" value="{{ $log->product_used }}">
                                    <input type="hidden" name="dosage" value="{{ $log->dosage }}">
                                    <button type="submit" class="bg-slate-100 text-slate-500 hover:bg-rose-500 hover:text-white px-3 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all border-none cursor-pointer" title="{{ __('Refaire à l’identique, maintenant') }}">
                                        <i class="fa-solid fa-rotate-right"></i>
                                    </button>
                                </form>
                            </td>
                            @endcan
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-broom text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucune opération de nettoyage enregistrée") }}</p>
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
</x-app-layout>
