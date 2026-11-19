{{--
    ALIMENTATION NATURELLE DU LOT — ce que le concentré ne dit pas.

    • Alimentation brute : l'ouvrier APPORTE herbe coupée, fanes, épluchures
      (herbivores et porcs : RawFeeding::FAMILLES).
    • Pâturage : les animaux vont la CHERCHER, gardés ou en autonomie
      (ruminants : GrazingSession::FAMILLES).

    La saisie n'existait qu'au terrain ; le bureau a désormais la même porte
    (BatchNaturalFeedingController → mêmes actions). Le bilan
    (Batch::alimentationNaturelle) complète l'indice de consommation, qui ne
    compte que l'aliment du stock. Les montants ne s'affichent qu'à qui voit
    l'argent (finances.voir).
--}}
@php
    $brutPossible = $batch->accepteAlimentationBrute();
    $paturagePossible = $batch->acceptePaturage();
    $rawFeedings = $batch->rawFeedings()->with(['employee:id,first_name,last_name', 'user:id,name', 'plot:id,name'])->limit(30)->get();
    $sortiesPaturage = $batch->grazingSessions()->with(['employee:id,first_name,last_name', 'user:id,name', 'plot:id,name'])->limit(30)->get();
    $afficher = $brutPossible || $paturagePossible || $rawFeedings->isNotEmpty() || $sortiesPaturage->isNotEmpty();
    $saisiePossible = $batch->isActive() && \Illuminate\Support\Facades\Gate::allows('elevage.C');
    $bilanNaturel = $afficher ? $batch->alimentationNaturelle() : null;
    $parcelles = $saisiePossible ? \App\Models\Plot::orderBy('name')->get(['id', 'name']) : collect();
@endphp

@if($afficher)
<div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden mb-8 not-italic" id="alimentation-naturelle">
    <div class="px-8 py-5 border-b border-slate-50 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">🌿 {{ __('Alimentation naturelle') }}</h3>
        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __('herbe apportée · pâturage — hors stock') }}</span>
    </div>

    {{-- BILAN — complète l'indice de consommation (concentré seul). --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 px-8 py-5">
        <div class="bg-emerald-50 rounded-2xl p-4">
            <p class="text-[8px] font-black uppercase tracking-widest text-emerald-600">{{ __('Jours nourris naturellement') }}</p>
            <p class="text-xl font-black text-slate-800">{{ $bilanNaturel['jours_naturels'] }} <span class="text-xs text-slate-400">/ {{ $bilanNaturel['jours_suivis'] }} j</span></p>
            @if($bilanNaturel['part_jours'] !== null)<p class="text-[9px] font-bold text-emerald-700">{{ __(':p % des jours', ['p' => number_format($bilanNaturel['part_jours'], 0)]) }}</p>@endif
        </div>
        <div class="bg-lime-50 rounded-2xl p-4">
            <p class="text-[8px] font-black uppercase tracking-widest text-lime-700">{{ __('Pâturage') }}</p>
            <p class="text-xl font-black text-slate-800">{{ number_format($bilanNaturel['heures_paturage'], 1, ',', ' ') }} h</p>
            <p class="text-[9px] font-bold text-lime-700">{{ __(':n sortie(s)', ['n' => $bilanNaturel['sorties']]) }}</p>
        </div>
        <div class="bg-amber-50 rounded-2xl p-4">
            <p class="text-[8px] font-black uppercase tracking-widest text-amber-700">{{ __('Alimentation brute') }}</p>
            <p class="text-xl font-black text-slate-800">{{ $bilanNaturel['distributions'] }}</p>
            <p class="text-[9px] font-bold text-amber-700">{{ __(':h h de travail', ['h' => number_format($bilanNaturel['minutes_distribution'] / 60, 1, ',', ' ')]) }}</p>
        </div>
        @can('finances.voir')
        <div class="bg-slate-50 rounded-2xl p-4">
            <p class="text-[8px] font-black uppercase tracking-widest text-slate-500">{{ __("Main-d'œuvre imputée") }}</p>
            <p class="text-xl font-black text-slate-800">{{ number_format($bilanNaturel['cout_main_oeuvre'], 0, ',', ' ') }} <span class="text-xs">{{ currency() }}</span></p>
            @if($bilanNaturel['cout_par_tete'] !== null)<p class="text-[9px] font-bold text-slate-500">{{ __(':c :cur / tête', ['c' => number_format($bilanNaturel['cout_par_tete'], 0, ',', ' '), 'cur' => currency()]) }}</p>@endif
        </div>
        @endcan
    </div>
    @if($bilanNaturel['manquants'] > 0)
        <p class="mx-8 mb-4 bg-rose-50 text-rose-700 rounded-2xl px-4 py-3 text-[10px] font-black">
            ⚠️ {{ __(':n tête(s) manquante(s) au retour du pâturage, cumulées — à retrouver ou à déclarer (pointage, incident).', ['n' => $bilanNaturel['manquants']]) }}
        </p>
    @endif

    {{-- SAISIE AU BUREAU — mêmes règles qu'au terrain. --}}
    @if($saisiePossible && ($brutPossible || $paturagePossible))
    <div class="px-8 pb-2 grid md:grid-cols-2 gap-4">
        @if($brutPossible)
        <x-collapsible-form :title="__('Nourrir (brut)')" icon="fa-leaf" :hint="__('herbe · fanes · temps passé')" :open="$errors->hasAny(['resource', 'minutes_spent', 'fed_at'])">
            <form method="POST" action="{{ route('batches.raw_feedings.store', $batch) }}" class="space-y-3">
                @csrf
                <input type="datetime-local" name="fed_at" required value="{{ old('fed_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                <input type="text" name="resource" required maxlength="120" list="ressources-brutes" value="{{ old('resource') }}" placeholder="{{ __('Herbe locale') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                <datalist id="ressources-brutes">@foreach(\App\Models\RawFeeding::RESSOURCES_COURANTES as $r)<option value="{{ __($r) }}">@endforeach</datalist>
                <div class="flex gap-2">
                    <input type="number" name="quantity" min="0" step="0.5" value="{{ old('quantity') }}" placeholder="{{ __('Quantité estimée') }}" class="flex-1 bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                    <select name="quantity_unit" class="bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                        @foreach(\App\Models\RawFeeding::UNITES as $u)<option value="{{ $u }}" @selected(old('quantity_unit', 'brouette') === $u)>{{ __($u) }}</option>@endforeach
                    </select>
                </div>
                <input type="number" name="minutes_spent" required min="1" max="720" value="{{ old('minutes_spent') }}" placeholder="{{ __('Temps passé (minutes)') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                @if($parcelles->isNotEmpty())
                <select name="plot_id" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                    <option value="">{{ __('— Coupé hors parcelle —') }}</option>
                    @foreach($parcelles as $p)<option value="{{ $p->id }}" @selected(old('plot_id') == $p->id)>{{ $p->name }}</option>@endforeach
                </select>
                @endif
                @error('batch_id')<p class="text-rose-600 text-[10px] font-bold">{{ $message }}</p>@enderror
                <button type="submit" class="w-full bg-emerald-600 text-white rounded-2xl p-3 text-[10px] font-black uppercase tracking-widest border-none cursor-pointer">{{ __('Enregistrer la distribution') }}</button>
            </form>
        </x-collapsible-form>
        @endif

        @if($paturagePossible)
        <x-collapsible-form :title="__('Sortie au pâturage')" icon="fa-sun" :hint="__('gardé ou en autonomie · têtes au retour')" :open="$errors->hasAny(['left_time', 'returned_time', 'heads_out', 'heads_back', 'returned_at'])">
            <form method="POST" action="{{ route('batches.grazings.store', $batch) }}" class="space-y-3" x-data="{ mode: {{ \Illuminate\Support\Js::from(old('mode', 'autonomie')) }} }">
                @csrf
                <input type="date" name="grazed_on" required value="{{ old('grazed_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                <div class="flex gap-2">
                    <label class="flex-1 text-[9px] font-black uppercase text-slate-400">{{ __('Départ') }}<input type="time" name="left_time" required value="{{ old('left_time', '08:00') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold"></label>
                    <label class="flex-1 text-[9px] font-black uppercase text-slate-400">{{ __('Retour') }}<input type="time" name="returned_time" required value="{{ old('returned_time', '17:00') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold"></label>
                </div>
                <div class="flex gap-2">
                    @foreach(\App\Models\GrazingSession::MODES as $code => $libelle)
                        <label class="flex-1 bg-slate-50 rounded-2xl p-3 text-[10px] font-black cursor-pointer" :class="mode === {{ \Illuminate\Support\Js::from($code) }} && 'ring-2 ring-lime-500'">
                            <input type="radio" name="mode" value="{{ $code }}" x-model="mode" class="mr-1">{{ __($libelle) }}
                        </label>
                    @endforeach
                </div>
                <div x-show="mode === 'autonomie'">
                    <input type="number" name="herder_minutes" min="0" max="960" value="{{ old('herder_minutes', \App\Actions\Batch\RecordGrazing::MINUTES_AUTONOMIE_PAR_DEFAUT) }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                    <p class="text-[9px] text-slate-400 font-bold mt-1">{{ __('Minutes passées à sortir puis ramener les bêtes (imputées au lot).') }}</p>
                </div>
                <div class="flex gap-2">
                    <label class="flex-1 text-[9px] font-black uppercase text-slate-400">{{ __('Têtes sorties') }}<input type="number" name="heads_out" required min="1" max="{{ $batch->current_quantity }}" value="{{ old('heads_out', $batch->current_quantity) }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold"></label>
                    <label class="flex-1 text-[9px] font-black uppercase text-slate-400">{{ __('Têtes rentrées') }}<input type="number" name="heads_back" required min="0" value="{{ old('heads_back', $batch->current_quantity) }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold"></label>
                </div>
                @if($parcelles->isNotEmpty())
                <select name="plot_id" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                    <option value="">{{ __('— Hors parcelle de la ferme —') }}</option>
                    @foreach($parcelles as $p)<option value="{{ $p->id }}" @selected(old('plot_id') == $p->id)>{{ $p->name }}</option>@endforeach
                </select>
                @endif
                <input type="text" name="zone" maxlength="120" value="{{ old('zone') }}" placeholder="{{ __('Zone (ex. jachère nord, bord du marigot)') }}" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-bold">
                @foreach(['batch_id', 'returned_at', 'heads_out', 'heads_back'] as $champ)
                    @error($champ)<p class="text-rose-600 text-[10px] font-bold">{{ $message }}</p>@enderror
                @endforeach
                <button type="submit" class="w-full bg-lime-600 text-white rounded-2xl p-3 text-[10px] font-black uppercase tracking-widest border-none cursor-pointer">{{ __('Enregistrer la sortie') }}</button>
            </form>
        </x-collapsible-form>
        @endif
    </div>
    @endif

    {{-- JOURNAL DU PÂTURAGE --}}
    @if($sortiesPaturage->isNotEmpty())
    <div class="px-8 pt-4 pb-2 text-[9px] font-black uppercase tracking-widest text-lime-700">☀️ {{ __('Sorties au pâturage') }}</div>
    <table class="w-full text-left text-xs">
        <thead><tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
            <th class="px-8 py-3">{{ __('Date') }}</th><th class="px-3 py-3">{{ __('Lieu') }}</th>
            <th class="px-3 py-3 text-right">{{ __('Durée') }}</th><th class="px-3 py-3">{{ __('Mode') }}</th>
            <th class="px-3 py-3 text-right">{{ __('Têtes') }}</th><th class="px-3 py-3">{{ __('Par') }}</th>
            @can('finances.voir')<th class="px-8 py-3 text-right">{{ __('Coût') }}</th>@endcan
        </tr></thead>
        <tbody class="divide-y divide-slate-50">
            @foreach($sortiesPaturage as $g)
            <tr>
                <td class="px-8 py-3 text-slate-500">{{ $g->left_at->format('d/m') }} {{ $g->left_at->format('H:i') }}–{{ $g->returned_at->format('H:i') }}</td>
                <td class="px-3 py-3 font-bold text-slate-700">{{ $g->lieu }}</td>
                <td class="px-3 py-3 text-right">{{ number_format($g->duration_minutes / 60, 1, ',', ' ') }} h</td>
                <td class="px-3 py-3">{{ __(\App\Models\GrazingSession::MODES[$g->mode] ?? $g->mode) }}</td>
                <td class="px-3 py-3 text-right font-black {{ $g->manquants > 0 ? 'text-rose-600' : 'text-slate-700' }}">{{ $g->heads_back }}/{{ $g->heads_out }}</td>
                <td class="px-3 py-3 text-slate-500">{{ $g->employee ? $g->employee->first_name . ' ' . $g->employee->last_name : ($g->user?->name ?? '—') }}</td>
                @can('finances.voir')
                <td class="px-8 py-3 text-right font-black {{ (float) $g->labor_cost_gnf > 0 ? 'text-slate-700' : 'text-amber-500' }}"
                    title="{{ (float) $g->labor_cost_gnf > 0 ? '' : __('Coût horaire inconnu : renseigner le salaire ou le coût horaire de l’employé') }}">
                    {{ (float) $g->labor_cost_gnf > 0 ? number_format($g->labor_cost_gnf, 0, ',', ' ') . ' ' . currency() : '—' }}
                </td>
                @endcan
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- JOURNAL DE L'ALIMENTATION BRUTE --}}
    @if($rawFeedings->isNotEmpty())
    <div class="px-8 pt-4 pb-2 text-[9px] font-black uppercase tracking-widest text-amber-700">🌿 {{ __('Alimentation brute') }}</div>
    <table class="w-full text-left text-xs">
        <thead><tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
            <th class="px-8 py-3">{{ __('Date') }}</th><th class="px-3 py-3">{{ __('Ressource') }}</th>
            <th class="px-3 py-3 text-right">{{ __('Quantité') }}</th><th class="px-3 py-3 text-right">{{ __('Temps') }}</th>
            <th class="px-3 py-3">{{ __('Par') }}</th>
            @can('finances.voir')<th class="px-8 py-3 text-right">{{ __('Coût') }}</th>@endcan
        </tr></thead>
        <tbody class="divide-y divide-slate-50">
            @foreach($rawFeedings as $rf)
            <tr>
                <td class="px-8 py-3 text-slate-500">{{ $rf->fed_at->format('d/m H:i') }}</td>
                <td class="px-3 py-3 font-bold text-slate-700">{{ $rf->resource }}@if($rf->plot)<span class="text-[9px] text-emerald-600 ml-1">· {{ $rf->plot->name }}</span>@endif</td>
                <td class="px-3 py-3 text-right">{{ $rf->quantity !== null ? rtrim(rtrim(number_format($rf->quantity, 2, ',', ' '), '0'), ',') . ' ' . __($rf->quantity_unit) : '—' }}</td>
                <td class="px-3 py-3 text-right">{{ $rf->minutes_spent }} min</td>
                <td class="px-3 py-3 text-slate-500">{{ $rf->employee ? $rf->employee->first_name . ' ' . $rf->employee->last_name : ($rf->user?->name ?? '—') }}</td>
                @can('finances.voir')
                <td class="px-8 py-3 text-right font-black {{ (float) $rf->labor_cost_gnf > 0 ? 'text-slate-700' : 'text-amber-500' }}"
                    title="{{ (float) $rf->labor_cost_gnf > 0 ? '' : __('Coût horaire inconnu : renseigner le salaire ou le coût horaire de l’employé') }}">
                    {{ (float) $rf->labor_cost_gnf > 0 ? number_format($rf->labor_cost_gnf, 0, ',', ' ') . ' ' . currency() : '—' }}
                </td>
                @endcan
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>
@endif
