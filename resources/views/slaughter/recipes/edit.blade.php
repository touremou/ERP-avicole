<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Recette — :name', ['name' => $recipe->name])" :subtitle="ucfirst(str_replace('_', ' ', $recipe->species_family)) . ' — ' . __('rendements attendus, nature des extrants, coefficients de valeur')" icon="fa-diagram-project" accent="rose" :back="route('slaughter.recipes.index')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if(session('success'))
                <div class="mb-6 p-5 bg-emerald-50 text-emerald-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-emerald-200">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-6 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('slaughter.recipes.update', $recipe) }}">
                @csrf @method('PUT')
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="grid grid-cols-2 gap-6 mb-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Nom de la recette") }} *</label>
                            <input type="text" name="name" value="{{ old('name', $recipe->name) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $recipe->is_active)) class="w-5 h-5 rounded">
                                <span class="text-[9px] font-black uppercase text-slate-500 tracking-widest">{{ __("Active (pré-remplit la découpe)") }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                    <th class="px-2 py-2 text-left">{{ __("Code") }}</th>
                                    <th class="px-2 py-2 text-left">{{ __("Libellé / article") }}</th>
                                    <th class="px-2 py-2 text-left">{{ __("Nature") }}</th>
                                    <th class="px-2 py-2 text-center">{{ __("Rdt attendu %") }}</th>
                                    <th class="px-2 py-2 text-center" title="{{ __('Prix de référence /kg — base de la répartition des coûts par valeur') }}">{{ __("Coef. valeur") }}</th>
                                    <th class="px-2 py-2 text-left">{{ __("Destination") }}</th>
                                    <th class="px-2 py-2 text-left">{{ __("Condit.") }}</th>
                                    <th class="px-2 py-2 text-left">{{ __("Calibre") }}</th>
                                    <th class="px-2 py-2 text-center">{{ __("Pré-rempli") }}</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @foreach($recipe->lines as $line)
                                <tr>
                                    <td class="px-2 py-2 text-[10px] font-black text-slate-400 uppercase">{{ $line->cut_code }}</td>
                                    <td class="px-2 py-2"><input type="text" name="lines[{{ $line->id }}][label]" value="{{ old("lines.{$line->id}.label", $line->label) }}" required class="w-full bg-slate-50 border-none rounded-xl p-2 text-[10px] font-black shadow-inner outline-none"></td>
                                    <td class="px-2 py-2">
                                        <select name="lines[{{ $line->id }}][output_type]" class="bg-slate-50 border-none rounded-xl p-2 text-[9px] font-black uppercase shadow-inner outline-none">
                                            @foreach(\App\Models\CuttingRecipeLine::OUTPUT_TYPES as $code => $label)
                                            <option value="{{ $code }}" @selected(old("lines.{$line->id}.output_type", $line->output_type) === $code)>{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-2 py-2"><input type="number" name="lines[{{ $line->id }}][expected_yield_percent]" value="{{ old("lines.{$line->id}.expected_yield_percent", $line->expected_yield_percent) }}" step="0.1" min="0" max="100" class="w-16 bg-slate-50 border-none rounded-xl p-2 text-[10px] font-black shadow-inner outline-none text-center"></td>
                                    <td class="px-2 py-2"><input type="number" name="lines[{{ $line->id }}][value_coefficient]" value="{{ old("lines.{$line->id}.value_coefficient", $line->value_coefficient) }}" step="0.01" min="0" class="w-20 bg-slate-50 border-none rounded-xl p-2 text-[10px] font-black shadow-inner outline-none text-center"></td>
                                    <td class="px-2 py-2">
                                        <select name="lines[{{ $line->id }}][default_destination]" class="bg-slate-50 border-none rounded-xl p-2 text-[9px] font-black uppercase shadow-inner outline-none">
                                            <option value="stock_frais" @selected(old("lines.{$line->id}.default_destination", $line->default_destination) === 'stock_frais')>{{ __("Stock Frais") }}</option>
                                            <option value="stock_congele" @selected(old("lines.{$line->id}.default_destination", $line->default_destination) === 'stock_congele')>{{ __("Congelé") }}</option>
                                            <option value="transformation" @selected(old("lines.{$line->id}.default_destination", $line->default_destination) === 'transformation')>{{ __("Transformation") }}</option>
                                            <option value="vente_directe" @selected(old("lines.{$line->id}.default_destination", $line->default_destination) === 'vente_directe')>{{ __("Vente Directe") }}</option>
                                            <option value="dechet" @selected(old("lines.{$line->id}.default_destination", $line->default_destination) === 'dechet')>{{ __("Déchet") }}</option>
                                        </select>
                                    </td>
                                    <td class="px-2 py-2">
                                        <select name="lines[{{ $line->id }}][default_packaging]" class="bg-slate-50 border-none rounded-xl p-2 text-[9px] font-black uppercase shadow-inner outline-none">
                                            <option value="">—</option>
                                            <option value="vrac" @selected(old("lines.{$line->id}.default_packaging", $line->default_packaging) === 'vrac')>{{ __("Vrac") }}</option>
                                            <option value="barquette" @selected(old("lines.{$line->id}.default_packaging", $line->default_packaging) === 'barquette')>{{ __("Barquette") }}</option>
                                            <option value="sachet" @selected(old("lines.{$line->id}.default_packaging", $line->default_packaging) === 'sachet')>{{ __("Sachet") }}</option>
                                        </select>
                                    </td>
                                    <td class="px-2 py-2"><input type="text" name="lines[{{ $line->id }}][default_calibre]" value="{{ old("lines.{$line->id}.default_calibre", $line->default_calibre) }}" maxlength="40" placeholder="S/M/L" class="w-14 bg-slate-50 border-none rounded-xl p-2 text-[10px] font-black uppercase shadow-inner outline-none text-center"></td>
                                    <td class="px-2 py-2 text-center">
                                        <input type="hidden" name="lines[{{ $line->id }}][is_default]" value="0">
                                        <input type="checkbox" name="lines[{{ $line->id }}][is_default]" value="1" @checked(old("lines.{$line->id}.is_default", $line->is_default)) class="w-4 h-4 rounded">
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        <button type="submit" form="delete-line-{{ $line->id }}" class="text-red-400 hover:text-red-600 border-none bg-transparent cursor-pointer" title="{{ __('Retirer ce morceau') }}"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <button type="submit" class="w-full bg-rose-500 text-white py-5 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-rose-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer la recette") }}
                </button>
            </form>

            {{-- Formulaires de suppression HORS du formulaire principal (HTML valide). --}}
            @foreach($recipe->lines as $line)
            <form id="delete-line-{{ $line->id }}" method="POST" action="{{ route('slaughter.recipes.lines.destroy', [$recipe, $line]) }}" onsubmit="return confirm(@json(__('Retirer ce morceau de la recette ?')))">
                @csrf @method('DELETE')
            </form>
            @endforeach

            {{-- AJOUT D'UN MORCEAU --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mt-6">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-4"><i class="fa-solid fa-plus text-rose-500 mr-1"></i> {{ __("Ajouter un morceau") }}</h3>
                <form method="POST" action="{{ route('slaughter.recipes.lines.store', $recipe) }}" class="grid grid-cols-4 gap-4 items-end">
                    @csrf
                    <div class="space-y-2">
                        <label class="text-[8px] font-black uppercase text-slate-400 ml-2">{{ __("Code (a-z, _)") }} *</label>
                        <input type="text" name="cut_code" required pattern="[a-z0-9_]+" placeholder="ex. pilon" class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black shadow-inner outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[8px] font-black uppercase text-slate-400 ml-2">{{ __("Libellé") }} *</label>
                        <input type="text" name="label" required placeholder="ex. Pilons" class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black shadow-inner outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[8px] font-black uppercase text-slate-400 ml-2">{{ __("Nature") }} *</label>
                        <select name="output_type" class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] font-black uppercase shadow-inner outline-none">
                            @foreach(\App\Models\CuttingRecipeLine::OUTPUT_TYPES as $code => $label)
                            <option value="{{ $code }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="bg-slate-900 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest border-none cursor-pointer hover:bg-slate-700">
                        <i class="fa-solid fa-plus mr-1"></i> {{ __("Ajouter") }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
