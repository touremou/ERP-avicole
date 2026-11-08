<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🐾 Taxonomie animale')" :subtitle="__('Espèces et souches / races proposées à la création d’un lot, au bureau comme sur le terrain')" icon="fa-paw" accent="slate">
            <x-slot name="actions">
                <a href="{{ route('settings.index') }}" class="flex items-center justify-center w-11 h-11 bg-white border border-slate-200 text-slate-400 hover:text-rose-600 rounded-xl transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10 italic font-bold">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-10">

            <x-flash />

            {{-- NOUVELLE ESPÈCE — l'administrateur complète le référentiel sans nous. --}}
            @can('admin.S')
            <details class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 not-italic">
                <summary class="cursor-pointer text-sm font-black text-slate-700 uppercase tracking-widest">➕ {{ __('Nouvelle espèce') }}</summary>
                <form method="POST" action="{{ route('admin.species.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-5">
                    @csrf
                    <input name="name_fr" required maxlength="100" placeholder="{{ __('Nom (ex. Lapin, Escargot)') }}" value="{{ old('name_fr') }}" class="md:col-span-2 bg-slate-50 border-none rounded-xl p-3 text-sm font-bold shadow-inner">
                    <input name="local_name" maxlength="100" placeholder="{{ __('Nom local (optionnel)') }}" value="{{ old('local_name') }}" class="bg-slate-50 border-none rounded-xl p-3 text-sm shadow-inner">
                    <select name="family" required class="bg-slate-50 border-none rounded-xl p-3 text-sm font-bold shadow-inner">
                        @foreach($families as $key => $meta)
                            <option value="{{ $key }}" @selected(old('family') === $key)>{{ $meta['icon'] }} {{ __($meta['label']) }}</option>
                        @endforeach
                    </select>
                    <input name="icon" maxlength="10" placeholder="{{ __('Icône (emoji)') }}" value="{{ old('icon') }}" class="bg-slate-50 border-none rounded-xl p-3 text-sm shadow-inner">
                    <input name="unit_label" maxlength="30" placeholder="{{ __('Unité (Tête)') }}" value="{{ old('unit_label') }}" class="bg-slate-50 border-none rounded-xl p-3 text-sm shadow-inner">
                    <input name="habitat_label" maxlength="50" placeholder="{{ __('Logement (Clapier, Bergerie…)') }}" value="{{ old('habitat_label') }}" class="bg-slate-50 border-none rounded-xl p-3 text-sm shadow-inner">
                    <input name="production_type" required maxlength="100" value="{{ old('production_type', __('Engraissement')) }}" class="bg-slate-50 border-none rounded-xl p-3 text-sm shadow-inner" title="{{ __('Type de production (un lot en exige un)') }}">
                    <input name="cycle_days" type="number" min="1" max="3650" required value="{{ old('cycle_days', 180) }}" class="bg-slate-50 border-none rounded-xl p-3 text-sm shadow-inner" title="{{ __('Durée de cycle (jours)') }}">
                    <button type="submit" class="md:col-span-3 bg-slate-900 text-white rounded-xl p-3 text-xs font-black uppercase tracking-widest border-none cursor-pointer">{{ __('Créer l’espèce') }}</button>
                </form>
            </details>
            @endcan

            @foreach($families as $familyKey => $familyMeta)
                @if(isset($species[$familyKey]))
                <div class="text-left">
                    <div class="flex items-center gap-3 mb-6">
                        <span class="text-3xl">{{ $familyMeta['icon'] }}</span>
                        <div>
                            <h3 class="text-lg font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ $familyMeta['label'] }}</h3>
                            <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest mt-1">{{ __(":count espèce(s)", ['count' => $species[$familyKey]->count()]) }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        @foreach($species[$familyKey] as $sp)
                        <div @class([
                            'bg-white rounded-[2.5rem] border shadow-sm transition-all duration-300 overflow-hidden',
                            'border-slate-100 opacity-60' => !$sp->is_active,
                            'border-' . $sp->color . '-100 shadow-' . $sp->color . '-50' => $sp->is_active,
                        ])>
                            <div class="p-7">
                                <div class="flex justify-between items-start mb-5">
                                    <div class="flex items-center gap-4">
                                        <div @class([
                                            'w-14 h-14 rounded-2xl flex items-center justify-center text-2xl shadow-inner',
                                            'bg-' . $sp->color . '-50' => $sp->is_active,
                                            'bg-slate-50' => !$sp->is_active,
                                        ])>{{ $sp->icon }}</div>
                                        <div>
                                            <p class="text-base font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ $sp->name_fr }}</p>
                                            @if($sp->local_name)
                                            <p class="text-[8px] font-black text-slate-300 italic mt-1 leading-none">{{ $sp->local_name }}</p>
                                            @endif
                                            <p class="text-[8px] font-black text-slate-400 uppercase mt-1 leading-none">{{ $sp->unit_label }} · {{ $sp->habitat_label }}</p>
                                        </div>
                                    </div>
                                    @can('admin.S')
                                    <div class="flex items-center gap-2">
                                        {{-- Suppression : uniquement pour une espèce orpheline (0 lot) —
                                             ménage d'une ligne créée à la main / reliquat. Une espèce
                                             utilisée ne se supprime pas, elle se désactive. --}}
                                        @if(($sp->batches_count ?? 0) === 0)
                                        <form action="{{ route('admin.species.destroy', $sp) }}" method="POST"
                                              onsubmit="return confirm(@js(__('Supprimer définitivement l’espèce « :name » ? (aucun lot n’y est rattaché)', ['name' => $sp->name_fr])))">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="w-8 h-8 rounded-lg bg-slate-50 text-slate-300 hover:bg-red-500 hover:text-white transition-all border-none cursor-pointer" title="{{ __('Supprimer cette espèce orpheline') }}">
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                        </form>
                                        @endif
                                        <form action="{{ route('admin.species.toggle', $sp) }}" method="POST">
                                            @csrf @method('PATCH')
                                            <button type="submit" @class([
                                                'relative w-12 h-6 rounded-full transition-all duration-300 shadow-inner focus:outline-none',
                                                'bg-emerald-500' => $sp->is_active,
                                                'bg-slate-200'   => !$sp->is_active,
                                            ])>
                                                <span @class([
                                                    'absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-all duration-300',
                                                    'left-6' => $sp->is_active,
                                                    'left-0.5' => !$sp->is_active,
                                                ])></span>
                                            </button>
                                        </form>
                                    </div>
                                    @endcan
                                </div>

                                {{-- Types de production --}}
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($sp->productionTypes->where('is_active', true) as $pt)
                                    <span class="px-3 py-1 bg-slate-50 rounded-xl text-[8px] font-black text-slate-500 uppercase italic border border-slate-100">
                                        {{ $pt->name_fr }}
                                    </span>
                                    @endforeach
                                </div>

                                {{-- SOUCHES / RACES — proposées à la création d'un lot (bureau et
                                     terrain, hors ligne compris). Une souche utilisée se désactive ;
                                     une souche jamais utilisée se supprime. --}}
                                <div class="mt-5 not-italic">
                                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ __('Souches / races') }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse($sp->breeds as $breed)
                                            <details class="inline-block">
                                                <summary @class(['list-none cursor-pointer px-3 py-1 rounded-xl text-[9px] font-black border',
                                                    'bg-emerald-50 text-emerald-700 border-emerald-100' => $breed->is_active,
                                                    'bg-slate-50 text-slate-400 border-slate-100 line-through' => ! $breed->is_active])>
                                                    {{ $breed->name }}@if($breed->batches_count) · {{ $breed->batches_count }}@endif
                                                </summary>
                                                @can('admin.S')
                                                <div class="mt-2 p-3 bg-slate-50 rounded-xl space-y-2">
                                                    <form method="POST" action="{{ route('admin.breeds.update', $breed) }}" class="flex gap-1">
                                                        @csrf @method('PUT')
                                                        <input name="name" value="{{ $breed->name }}" maxlength="100" class="flex-1 bg-white border-none rounded-lg p-2 text-[10px] font-bold">
                                                        <button class="px-2 rounded-lg bg-slate-900 text-white text-[9px] font-black border-none cursor-pointer">{{ __('Renommer') }}</button>
                                                    </form>
                                                    <div class="flex gap-1">
                                                        <form method="POST" action="{{ route('admin.breeds.toggle', $breed) }}">
                                                            @csrf @method('PATCH')
                                                            <button class="px-2 py-1 rounded-lg bg-white text-[9px] font-black border border-slate-200 cursor-pointer">{{ $breed->is_active ? __('Désactiver') : __('Réactiver') }}</button>
                                                        </form>
                                                        @if(! $breed->batches_count)
                                                        <form method="POST" action="{{ route('admin.breeds.destroy', $breed) }}" onsubmit="return confirm(@js(__('Supprimer la souche « :name » ?', ['name' => $breed->name])))">
                                                            @csrf @method('DELETE')
                                                            <button class="px-2 py-1 rounded-lg bg-white text-rose-600 text-[9px] font-black border border-rose-100 cursor-pointer">{{ __('Supprimer') }}</button>
                                                        </form>
                                                        @endif
                                                    </div>
                                                </div>
                                                @endcan
                                            </details>
                                        @empty
                                            <span class="text-[9px] text-slate-300">{{ __('Aucune souche : ajoutez-en une.') }}</span>
                                        @endforelse
                                    </div>
                                    @can('admin.S')
                                    <form method="POST" action="{{ route('admin.species.breeds.store', $sp) }}" class="flex gap-1 mt-2">
                                        @csrf
                                        <input name="name" required maxlength="100" placeholder="{{ __('Nouvelle souche (ex. Djallonké)') }}" class="flex-1 bg-slate-50 border-none rounded-lg p-2 text-[10px] font-bold shadow-inner">
                                        <button class="px-3 rounded-lg bg-emerald-600 text-white text-[9px] font-black uppercase border-none cursor-pointer">{{ __('Ajouter') }}</button>
                                    </form>
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-[8px] font-black text-slate-400 uppercase">✏️ {{ __('Modifier l’espèce') }}</summary>
                                        <form method="POST" action="{{ route('admin.species.update', $sp) }}" class="grid grid-cols-2 gap-1 mt-2">
                                            @csrf @method('PUT')
                                            <input name="name_fr" required value="{{ $sp->name_fr }}" maxlength="100" class="col-span-2 bg-slate-50 border-none rounded-lg p-2 text-[10px] font-bold">
                                            <input name="local_name" value="{{ $sp->local_name }}" maxlength="100" placeholder="{{ __('Nom local') }}" class="col-span-2 bg-slate-50 border-none rounded-lg p-2 text-[10px]">
                                            <input name="icon" value="{{ $sp->icon }}" maxlength="10" class="bg-slate-50 border-none rounded-lg p-2 text-[10px]">
                                            <input name="unit_label" value="{{ $sp->unit_label }}" maxlength="30" class="bg-slate-50 border-none rounded-lg p-2 text-[10px]">
                                            <input name="habitat_label" value="{{ $sp->habitat_label }}" maxlength="50" class="col-span-2 bg-slate-50 border-none rounded-lg p-2 text-[10px]">
                                            <button class="col-span-2 rounded-lg bg-slate-900 text-white text-[9px] font-black uppercase p-2 border-none cursor-pointer">{{ __('Enregistrer') }}</button>
                                        </form>
                                    </details>
                                    @endcan
                                </div>

                                {{-- Indicateurs suivis --}}
                                <div class="flex gap-2 mt-4">
                                    @if($sp->tracks_eggs)
                                    <span class="px-2 py-1 bg-yellow-50 rounded-lg text-[7px] font-black text-yellow-600 border border-yellow-100">{{ __("🥚 Œufs") }}</span>
                                    @endif
                                    @if($sp->tracks_milk)
                                    <span class="px-2 py-1 bg-blue-50 rounded-lg text-[7px] font-black text-blue-600 border border-blue-100">{{ __("🥛 Lait") }}</span>
                                    @endif
                                    @if($sp->tracks_water_quality)
                                    <span class="px-2 py-1 bg-cyan-50 rounded-lg text-[7px] font-black text-cyan-600 border border-cyan-100">{{ __("💧 Eau") }}</span>
                                    @endif
                                </div>

                                {{-- DURÉE D'INCUBATION — corrigeable par la ferme.
                                     Elle vivait codée en dur dans un contrôleur : un
                                     canard de Barbarie (35 j) ne pouvait pas être
                                     distingué d'un canard commun (28 j). Affichée
                                     pour les seules espèces qui pondent. --}}
                                @if($sp->tracks_eggs)
                                    {{-- ARBITRAGE EN ATTENTE — on aligne sur la porte,
                                         sans rien ouvrir ni fermer.

                                         Trois déclarations, deux réponses :
                                         `SpeciesController::updateIncubation` exige
                                         admin.M (seule méthode du contrôleur à ne pas
                                         exiger admin.S), et routes/web.php l'annonce —
                                         « la seule donnée zootechnique que la ferme
                                         doit pouvoir corriger sans nous ». Mais la
                                         route hérite du groupe `can:S` de
                                         l'administration, qui S'AJOUTE : le verrou
                                         effectif est admin.S, et l'exception voulue
                                         n'a jamais pris effet.

                                         Sous admin.M, le champ s'offrait donc à des
                                         comptes que la porte refuse : on tape 35 et
                                         l'envoi échoue. On retire cette fausse
                                         promesse en attendant la décision — ouvrir la
                                         route à admin.M relèverait les droits d'un
                                         référentiel partagé, ce qui appartient à
                                         l'exploitant, pas à cette correction. --}}
                                    @can('admin.S')
                                    <form method="POST" action="{{ route('admin.species.incubation', $sp) }}"
                                          class="flex items-center gap-2 mt-4">
                                        @csrf @method('PATCH')
                                        <label class="text-[7px] font-black text-slate-400 uppercase tracking-widest">{{ __("Incubation") }}</label>
                                        <input type="number" name="incubation_days" min="10" max="60"
                                               value="{{ $sp->incubation_days }}"
                                               placeholder="{{ setting('couvoir.incubation_days', 21) }}"
                                               class="w-16 bg-slate-50 border-none rounded-lg p-2 text-[10px] font-black text-slate-800 shadow-inner text-right">
                                        <span class="text-[7px] font-black text-slate-400 uppercase">{{ __("jours") }}</span>
                                        <button type="submit" class="px-3 py-2 bg-slate-100 rounded-lg text-[7px] font-black text-slate-600 uppercase border-none cursor-pointer hover:bg-slate-900 hover:text-white transition-all">
                                            {{ __("Enregistrer") }}
                                        </button>
                                    </form>
                                    <p class="text-[7px] font-black text-slate-300 uppercase mt-1 italic">
                                        {{ __("Vide = réglage de la ferme (:n j)", ['n' => setting('couvoir.incubation_days', 21)]) }}
                                    </p>
                                    @endcan
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            @endforeach

        </div>
    </div>
</x-app-layout>
