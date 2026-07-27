{{--
    TABLEAU DE BORD DU LABORATOIRE — mélange en cours face à la norme.

    Partagé par la création et l'édition. L'écran d'édition — celui où l'on
    OPTIMISE une recette — n'affichait aucune teneur : on y travaillait à
    l'aveugle. Alimenté par FormulaLab (cf. lab-script), lui-même dérivé de
    FoodNorm::NUTRIENTS.

    @param \Illuminate\Support\Collection $norms  normes actives du référentiel
    @param string|null $selected                  animal_type déjà rattaché
--}}
@php $selected = $selected ?? null; @endphp

<div class="bg-slate-900 p-8 rounded-[3rem] shadow-2xl text-white space-y-6">
    <div>
        <label class="block text-[10px] font-black text-blue-400 uppercase mb-2 ml-2 italic tracking-widest leading-none">
            {{ __("Référentiel nutritionnel") }}
            <span class="text-white/30 normal-case">{{ __("(cible de comparaison)") }}</span>
        </label>
        <select data-lab-norm
                class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 font-black text-white text-xs italic appearance-none cursor-pointer">
            <option value="">{{ __("-- Aucune norme --") }}</option>
            @foreach($norms as $norm)
                <option value="{{ $norm->animal_type }}"
                        @selected($selected && $norm->animal_type === $selected)
                        @foreach(\App\Models\FoodNorm::NUTRIENTS as $key => $nutrient) data-t-{{ $key }}="{{ (float) $norm->{$nutrient['target']} }}" @endforeach>
                    {{ \Illuminate\Support\Str::upper($norm->name) }}@if($norm->targetPrice()) — {{ number_format($norm->targetPrice(), 0, ',', ' ') }} {{ currency() }}/kg @endif
                </option>
            @endforeach
        </select>
    </div>

    <div class="space-y-3">
        @foreach(\App\Models\FoodNorm::NUTRIENTS as $key => $nutrient)
            <div class="space-y-1">
                <div class="flex justify-between items-baseline text-[8px] font-black uppercase italic gap-2">
                    <span class="opacity-50">{{ __($nutrient['label']) }}</span>
                    <span class="text-right">
                        <span data-lab-real="{{ $key }}">0</span> /
                        <span data-lab-target="{{ $key }}" class="opacity-60">—</span>
                        <small class="opacity-30">{{ $nutrient['unit'] }}</small>
                        <span data-lab-note="{{ $key }}" class="text-amber-300 normal-case ml-1"></span>
                    </span>
                </div>
                <div class="h-1.5 bg-slate-800 rounded-full overflow-hidden">
                    <div data-lab-bar="{{ $key }}" class="h-full bg-slate-600 transition-all duration-500" style="width: 0%"></div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-4 pt-4 border-t border-white/10">
        <div>
            <p class="text-[9px] font-black text-blue-400 uppercase italic leading-none mb-1">{{ __("Coût de revient") }}</p>
            <p class="text-2xl font-black italic tracking-tighter leading-none">
                <span data-lab-cost>0</span> <small class="text-[10px] opacity-40">{{ currency() }}/kg</small>
            </p>
        </div>
        <div class="text-right">
            <p class="text-[9px] font-black text-blue-400 uppercase italic leading-none mb-1">{{ __("Total des parts") }}</p>
            <p class="text-2xl font-black italic tracking-tighter leading-none" data-lab-total>0%</p>
        </div>
    </div>
</div>
