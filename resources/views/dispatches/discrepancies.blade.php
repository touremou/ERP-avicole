<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <x-page-header :title="__('Écarts & Litiges')" :subtitle="__('Rapports de réconciliation — Three-Way Matching')" icon="fa-triangle-exclamation" accent="orange" />
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- STATS --}}
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div @class(['p-5 rounded-[2rem] border text-center', $stats['total_open'] > 0 ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'])>
                    <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $stats['total_open'] > 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ __("Écarts non résolus") }}</p>
                    <p class="text-3xl font-black {{ $stats['total_open'] > 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $stats['total_open'] }}</p>
                </div>
                <div @class(['p-5 rounded-[2rem] border text-center', $stats['total_critical'] > 0 ? 'bg-red-50 border-red-300 animate-pulse' : 'bg-slate-50 border-slate-200'])>
                    <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $stats['total_critical'] > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ __("Critiques") }}</p>
                    <p class="text-3xl font-black {{ $stats['total_critical'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $stats['total_critical'] }}</p>
                </div>
                <div class="bg-amber-50 p-5 rounded-[2rem] border border-amber-200 text-center">
                    <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-1">{{ __("Total manquant") }}</p>
                    <p class="text-3xl font-black text-amber-600">{{ number_format($stats['total_missing'], 0) }}</p>
                </div>
            </div>

            {{-- FILTRES --}}
            <form method="GET" class="mb-8 flex flex-wrap gap-3 items-center">
                <select name="severity" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Toutes sévérités") }}</option>
                    <option value="critique" {{ request('severity') === 'critique' ? 'selected' : '' }}>{{ __("Critique") }}</option>
                    <option value="attention" {{ request('severity') === 'attention' ? 'selected' : '' }}>{{ __("Attention") }}</option>
                    <option value="normal" {{ request('severity') === 'normal' ? 'selected' : '' }}>{{ __("Normal") }}</option>
                </select>
                <select name="resolution" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Toutes résolutions") }}</option>
                    <option value="en_cours" {{ request('resolution') === 'en_cours' ? 'selected' : '' }}>{{ __("En cours") }}</option>
                    <option value="justifie" {{ request('resolution') === 'justifie' ? 'selected' : '' }}>{{ __("Justifié") }}</option>
                    <option value="injustifie" {{ request('resolution') === 'injustifie' ? 'selected' : '' }}>{{ __("Injustifié") }}</option>
                    <option value="enquete" {{ request('resolution') === 'enquete' ? 'selected' : '' }}>{{ __("Enquête") }}</option>
                </select>
                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border-none cursor-pointer">{{ __("Filtrer") }}</button>
            </form>

            {{-- LISTE DES RAPPORTS --}}
            <div class="space-y-4">
                @forelse($reports as $report)
                <div @class([
                    'bg-white p-6 rounded-[2.5rem] border-2 shadow-sm transition-all hover:shadow-md',
                    'border-red-300' => $report->severity === 'critique' && $report->resolution === 'en_cours',
                    'border-amber-300' => $report->severity === 'attention' && $report->resolution === 'en_cours',
                    'border-slate-200' => $report->resolution !== 'en_cours',
                    'border-slate-100' => $report->severity === 'normal' && $report->resolution === 'en_cours',
                ])>
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div @class([
                                'w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg',
                                'bg-red-500' => $report->severity === 'critique',
                                'bg-amber-500' => $report->severity === 'attention',
                                'bg-slate-400' => $report->severity === 'normal',
                            ])>
                                <i class="fa-solid fa-{{ $report->severity === 'critique' ? 'skull-crossbones' : ($report->severity === 'attention' ? 'exclamation' : 'info') }} text-lg"></i>
                            </div>
                            <div>
                                <a href="{{ route('dispatches.show', $report->dispatch) }}" class="no-underline">
                                    <p class="text-sm font-black text-slate-900 uppercase italic">{{ $report->dispatch->dispatch_number }}</p>
                                </a>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">
                                    {{ $report->dispatch->destination }} — {{ $report->created_at->translatedFormat('d/m/Y') }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-6">
                            <div class="text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Écart") }}</p>
                                <p class="text-lg font-black {{ $report->discrepancy_rate > 5 ? 'text-red-600' : 'text-amber-600' }}">{{ $report->discrepancy_rate }}%</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[8px] font-black text-red-400 uppercase">{{ __("Manquant") }}</p>
                                <p class="text-lg font-black text-red-600">{{ $report->total_missing }}</p>
                            </div>
                            <span @class([
                                'text-[8px] font-black uppercase px-4 py-2 rounded-full',
                                'bg-amber-100 text-amber-700' => $report->resolution === 'en_cours',
                                'bg-emerald-50 text-emerald-600' => $report->resolution === 'justifie',
                                'bg-red-50 text-red-600' => $report->resolution === 'injustifie',
                                'bg-blue-50 text-blue-600' => $report->resolution === 'enquete',
                            ])>{{ str_replace('_', ' ', $report->resolution) }}</span>
                            <a href="{{ route('dispatches.show', $report->dispatch) }}" class="text-slate-400 hover:text-slate-700 no-underline">
                                <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="bg-emerald-50 p-12 rounded-[3rem] text-center border border-emerald-200">
                    <i class="fa-solid fa-shield-check text-emerald-300 text-4xl mb-4 block"></i>
                    <p class="text-[10px] text-emerald-600 uppercase tracking-widest font-black">{{ __("Aucun écart détecté — Chaîne logistique intègre") }}</p>
                </div>
                @endforelse
            </div>

            <div class="mt-6">{{ $reports->withQueryString()->links() }}</div>
        </div>
    </div>
</x-app-layout>
