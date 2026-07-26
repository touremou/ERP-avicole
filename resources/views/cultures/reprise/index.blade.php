@php
    /**
     * Reprise d'historique cultures — parcours en DEUX TEMPS.
     *
     * 1. Télécharger le modèle, le remplir avec les techniciens.
     * 2. Téléverser → LIRE LE RAPPORT → corriger si besoin → valider.
     *
     * Rien n'est enregistré avant la validation explicite, et l'import est
     * tout-ou-rien : jamais un historique à moitié entré.
     */
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Reprise d\'historique')" :subtitle="__('Importer en lot les cultures déjà en place et leurs activités')"
                       icon="fa-file-import" accent="green" :back="route('crop-cycles.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold space-y-8">

            @if(session('success'))
                <div class="bg-green-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-rose-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem]">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            {{-- ÉTAPE 1 : le modèle --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-4">
                    {{ __("1. Télécharger le modèle") }}
                </h3>
                <p class="text-[10px] font-bold text-slate-500 italic leading-relaxed mb-6">
                    {{ __("Quatre onglets à remplir dans l'ordre : Parcelles, Cycles, Intrants, Récoltes. Les codes que vous écrivez font le lien entre les onglets. Les listes déroulantes vous donnent les valeurs acceptées.") }}
                </p>
                <p class="text-[9px] font-bold text-slate-400 uppercase italic leading-relaxed mb-6">
                    {{ __("Le modèle est généré à la demande : il contient VOS employés et VOS cultures au moment du téléchargement. Re-téléchargez-le si vous ajoutez du personnel.") }}
                </p>
                <a href="{{ route('crop-backfill.template') }}"
                   class="inline-block bg-slate-900 text-white px-10 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all no-underline">
                    <i class="fa-solid fa-file-excel mr-2 text-green-400"></i> {{ __("Télécharger le modèle Excel") }}
                </a>
            </div>

            {{-- ÉTAPE 2 : téléverser --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-4">
                    {{ __("2. Téléverser pour analyse") }}
                </h3>
                <p class="text-[10px] font-bold text-slate-500 italic leading-relaxed mb-6">
                    {{ __("Rien n'est enregistré à cette étape : l'application lit le fichier et vous montre les erreurs ligne par ligne.") }}
                </p>
                <form action="{{ route('crop-backfill.analyse') }}" method="POST" enctype="multipart/form-data" class="flex flex-wrap gap-4 items-end">
                    @csrf
                    <div class="flex-1 min-w-[240px]">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Fichier rempli (.xlsx)") }}</label>
                        <input type="file" name="file" accept=".xlsx,.xls" required
                               class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">
                    </div>
                    <button type="submit" class="bg-slate-100 text-slate-700 px-10 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-slate-200 transition-all">
                        <i class="fa-solid fa-magnifying-glass mr-2"></i> {{ __("Analyser") }}
                    </button>
                </form>
            </div>

            {{-- ÉTAPE 3 : le rapport --}}
            @if($report)
                <div class="bg-white p-8 rounded-[3rem] border-2 {{ $report['ok'] ? 'border-green-200' : 'border-rose-200' }} shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-2">
                        {{ __("3. Rapport d'analyse") }}
                    </h3>
                    <p class="text-[9px] font-bold text-slate-400 uppercase italic mb-6">{{ $report['name'] }}</p>

                    {{-- Ce qui a été LU --}}
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                        @foreach([
                            'plots' => __("Parcelles"), 'cycles' => __("Cycles"),
                            'inputs' => __("Intrants"), 'harvests' => __("Récoltes"),
                        ] as $key => $label)
                            <div class="bg-slate-50 p-5 rounded-[2rem]">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2">{{ $label }}</p>
                                <p class="text-2xl font-black text-slate-900 leading-none">{{ $report['counts'][$key] }}</p>
                                <p class="text-[8px] font-bold text-slate-400 uppercase italic mt-2">{{ __("ligne(s) lue(s)") }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if($report['ok'])
                        <div class="bg-green-50 border border-green-100 p-6 rounded-[2rem] mb-6">
                            <p class="text-[11px] font-black text-green-700 uppercase italic">
                                <i class="fa-solid fa-circle-check mr-2"></i>{{ __("Aucune erreur — le fichier est prêt à être importé.") }}
                            </p>
                            @if(count($report['existing']['plots']) || count($report['existing']['cycles']))
                                <p class="text-[9px] font-bold text-green-600 uppercase italic mt-3 leading-relaxed">
                                    {{ __("Déjà en base et donc RÉUTILISÉS (pas de doublon) :") }}
                                    @if(count($report['existing']['plots']))
                                        {{ __("parcelles") }} {{ implode(', ', $report['existing']['plots']) }}.
                                    @endif
                                    @if(count($report['existing']['cycles']))
                                        {{ __("cycles") }} {{ implode(', ', $report['existing']['cycles']) }}.
                                    @endif
                                </p>
                            @endif
                            @if($report['counts']['inputs'] > 0 || $report['counts']['harvests'] > 0)
                                {{-- Avertissement honnête : les activités n'ont pas de clé
                                     naturelle fiable, elles seraient ré-ajoutées. --}}
                                <p class="text-[9px] font-black text-amber-600 uppercase italic mt-3 leading-relaxed">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                    {{ __("Intrants et récoltes n'ont pas de clé unique : si vous avez déjà importé ces onglets, ils seront ajoutés une seconde fois. Videz-les avant de re-téléverser un fichier corrigé.") }}
                                </p>
                            @endif
                        </div>

                        <form action="{{ route('crop-backfill.commit') }}" method="POST">
                            @csrf
                            <input type="hidden" name="path" value="{{ $report['path'] }}">
                            <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                                <i class="fa-solid fa-database mr-2 text-green-400"></i> {{ __("Importer définitivement") }}
                            </button>
                            <p class="text-[9px] font-bold text-slate-400 uppercase italic mt-4 leading-relaxed">
                                {{ __("Tout-ou-rien : si une règle métier refuse une ligne, rien n'est enregistré.") }}
                            </p>
                        </form>
                    @else
                        <div class="bg-rose-50 border border-rose-100 p-6 rounded-[2rem]">
                            <p class="text-[11px] font-black text-rose-700 uppercase italic mb-4">
                                <i class="fa-solid fa-circle-exclamation mr-2"></i>
                                {{ trans_choice('{1} :count erreur à corriger|[2,*] :count erreurs à corriger', $report['error_total'], ['count' => $report['error_total']]) }}
                            </p>
                            <p class="text-[9px] font-bold text-rose-600 uppercase italic mb-6 leading-relaxed">
                                {{ __("Corrigez ces lignes dans votre fichier, puis re-téléversez-le. Rien n'a été enregistré.") }}
                            </p>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="border-b border-rose-100">
                                            <th class="pb-2 text-[8px] font-black text-rose-400 uppercase tracking-widest">{{ __("Onglet") }}</th>
                                            <th class="pb-2 text-[8px] font-black text-rose-400 uppercase tracking-widest">{{ __("Ligne") }}</th>
                                            <th class="pb-2 text-[8px] font-black text-rose-400 uppercase tracking-widest">{{ __("Problème") }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($report['errors'] as $error)
                                            <tr class="border-b border-rose-50 last:border-0">
                                                <td class="py-2 text-[10px] font-black uppercase text-slate-700 whitespace-nowrap">{{ $error['sheet'] }}</td>
                                                <td class="py-2 px-4 text-[10px] font-black text-rose-600">{{ $error['line'] }}</td>
                                                <td class="py-2 text-[10px] font-bold text-slate-600 italic">{{ $error['message'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if($report['error_total'] > count($report['errors']))
                                <p class="text-[9px] font-black text-rose-500 uppercase italic mt-4">
                                    {{ __("… et :n autre(s) : corrigez d'abord celles-ci.", ['n' => $report['error_total'] - count($report['errors'])]) }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
