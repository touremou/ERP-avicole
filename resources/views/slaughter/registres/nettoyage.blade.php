<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Registre nettoyage & désinfection')" :subtitle="__('E7 — Exécution du plan de nettoyage')" icon="fa-broom" accent="rose" :back="route('slaughter.dashboard')">
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

            {{-- SAISIE RAPIDE --}}
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
                            <th class="px-5 py-4 text-center">{{ __("Synchronisé le") }}</th>
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
                            <td class="px-5 py-4 text-center text-[9px] font-black text-slate-400">{{ $log->synced_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-8 py-16 text-center">
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
