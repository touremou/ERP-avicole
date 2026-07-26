@php
    /**
     * Confrontation délai avant récolte ↔ récoltes effectives.
     *
     * Cet écran est HONNÊTE sur son niveau de garantie : le délai avant récolte
     * n'était pas enregistré avant sa correction, donc l'historique ne peut pas
     * être audité automatiquement. On distingue donc ce qui est ÉTABLI (délai
     * connu et dépassé) de ce qui est À VÉRIFIER contre la notice du produit.
     */
    $style = [
        'depasse'    => ['bg-rose-600', 'border-rose-200', __('Délai dépassé')],
        'a_verifier' => ['bg-amber-500', 'border-amber-200', __('À vérifier')],
        'conforme'   => ['bg-emerald-600', 'border-emerald-100', __('Conforme')],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Délai avant récolte')" :subtitle="__('Traitements phytosanitaires suivis d\'une récolte')"
                       icon="fa-flask-vial" accent="amber" :back="route('crop-reports.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold space-y-8">

            {{-- L'avertissement vient AVANT les chiffres : sans lui, un « 0 dépassé »
                 se lirait comme une conformité prouvée, ce qu'il n'est pas. --}}
            <div class="bg-slate-900 text-white p-8 rounded-[3rem] shadow-xl">
                <h3 class="text-[10px] font-black uppercase tracking-widest italic mb-4">
                    <i class="fa-solid fa-circle-info mr-2 text-amber-400"></i>{{ __("Ce que cette page garantit — et ce qu'elle ne garantit pas") }}
                </h3>
                <p class="text-[10px] font-bold text-slate-300 italic leading-relaxed mb-3">
                    {{ __("Le délai avant récolte n'était pas enregistré avant la correction du :date : il était saisi, validé, puis perdu. Les traitements antérieurs n'ont donc aucun délai en base, et rien ne peut le reconstituer.", ['date' => '26/07/2026']) }}
                </p>
                <p class="text-[10px] font-bold text-slate-300 italic leading-relaxed">
                    {{ __("Conséquence : « Délai dépassé » est un constat établi (délai connu, récolte dans la fenêtre). « À vérifier » signifie que le délai est inconnu — reprenez la notice du produit et tranchez. Un total de zéro dépassement ne prouve pas la conformité de l'historique.") }}
                </p>
            </div>

            {{-- Filtres --}}
            <form method="GET" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Historique (mois)") }}</label>
                    <input type="number" name="months" min="1" max="36" value="{{ $months }}"
                           class="bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic w-32">
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Fenêtre après traitement (jours)") }}</label>
                    <input type="number" name="window" min="1" max="120" value="{{ $window }}"
                           class="bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic w-32">
                </div>
                <button type="submit" class="bg-slate-100 text-slate-700 px-8 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-slate-200 transition-all">
                    {{ __("Appliquer") }}
                </button>
                <a href="{{ route('crop-reports.withdrawal.pdf', ['months' => $months, 'window' => $window]) }}"
                   class="ml-auto bg-slate-900 text-white px-8 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-rose-600 transition-all no-underline">
                    <i class="fa-solid fa-file-pdf mr-2"></i> {{ __("PDF") }}
                </a>
            </form>

            {{-- Compteurs --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach([
                    'depasse'    => [__("Délai dépassé"), 'text-rose-600'],
                    'a_verifier' => [__("À vérifier"), 'text-amber-600'],
                    'conforme'   => [__("Conforme"), 'text-emerald-600'],
                ] as $key => [$label, $colour])
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ $label }}</p>
                        <p class="text-3xl font-black {{ $colour }} leading-none">{{ $counts[$key] }}</p>
                        <p class="text-[8px] font-bold text-slate-400 uppercase italic mt-2">{{ __("cas") }}</p>
                    </div>
                @endforeach
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ __("Traitements lus") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $treatments }}</p>
                    <p class="text-[8px] font-bold text-slate-400 uppercase italic mt-2">{{ __("depuis le") }} {{ $since->format('d/m/Y') }}</p>
                </div>
            </div>

            {{-- Cas à confronter --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">
                    {{ __("Cas à confronter") }}
                    <span class="ml-2 bg-slate-900 text-white px-3 py-1 rounded-full">{{ $rows->count() }}</span>
                </h3>

                @if($rows->isEmpty())
                    <p class="text-[10px] font-black text-slate-500 uppercase italic">
                        {{ __("Aucune récolte n'a suivi un traitement phytosanitaire dans la fenêtre choisie.") }}
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b border-slate-100">
                                    <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Verdict") }}</th>
                                    <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Cycle / Parcelle") }}</th>
                                    <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Produit appliqué") }}</th>
                                    <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Application") }}</th>
                                    <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Récolte") }}</th>
                                    <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Écart") }}</th>
                                    <th class="pb-3 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("DAR en base") }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    @php [$badge, $border, $label] = $style[$row['verdict']]; @endphp
                                    <tr class="border-b border-slate-50 last:border-0">
                                        <td class="py-4">
                                            <span class="{{ $badge }} text-white px-3 py-1.5 rounded-full text-[8px] font-black uppercase tracking-widest whitespace-nowrap">{{ $label }}</span>
                                        </td>
                                        <td class="py-4 text-[10px] font-black text-slate-800">
                                            @if($row['cycle'])
                                                <a href="{{ route('crop-cycles.show', $row['cycle']->id) }}" class="no-underline text-slate-800 hover:text-emerald-600">
                                                    {{ $row['cycle']->code }}
                                                </a>
                                                <span class="block text-[8px] font-bold text-slate-400 uppercase">{{ $row['cycle']->plot->name ?? '—' }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-4 text-[10px] font-bold text-slate-600 italic">{{ $row['treatment']->name }}</td>
                                        <td class="py-4 text-[10px] font-bold text-slate-500">{{ $row['treatment']->input_date->format('d/m/Y') }}</td>
                                        <td class="py-4 text-[10px] font-bold text-slate-500">{{ $row['harvest']->harvest_date->format('d/m/Y') }}</td>
                                        <td class="py-4 text-[10px] font-black {{ $row['verdict'] === 'depasse' ? 'text-rose-600' : 'text-slate-700' }}">
                                            {{ trans_choice('{0} même jour|{1} :n jour|[2,*] :n jours', $row['gap_days'], ['n' => $row['gap_days']]) }}
                                        </td>
                                        <td class="py-4 text-[10px] font-black">
                                            @if($row['dar'])
                                                <span class="text-slate-800">{{ $row['dar'] }} {{ __("j") }}</span>
                                            @else
                                                <span class="text-amber-600 uppercase">{{ __("non enregistré") }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
