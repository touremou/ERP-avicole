<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <a href="{{ route('tasks.templates') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">Modifier le template</h2>
                <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ $template->name }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">

            @if($errors->any())
                <div class="mb-6 p-4 bg-red-500 text-white rounded-2xl font-black text-[10px] uppercase tracking-widest">
                    @foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('tasks.templates.update', $template) }}" class="space-y-5">
                @csrf @method('PUT')

                <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm text-left space-y-4">
                    <div>
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Nom</label>
                        <input type="text" name="name" value="{{ old('name', $template->name) }}" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-sm font-black shadow-inner outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Catégorie</label>
                            <select name="category" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                                @foreach(['alimentation' => '🌾 Alimentation', 'collecte' => '🥚 Collecte', 'controle' => '📋 Contrôle', 'nettoyage' => '🧹 Nettoyage', 'sante' => '💉 Santé', 'maintenance' => '🔧 Maintenance'] as $k => $v)
                                    <option value="{{ $k }}" {{ $template->category === $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Fréquence</label>
                            <select name="frequency" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                                @foreach(['quotidien' => 'Quotidien', 'hebdo' => 'Hebdomadaire', 'mensuel' => 'Mensuel'] as $k => $v)
                                    <option value="{{ $k }}" {{ $template->frequency === $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Heure</label>
                            <input type="time" name="scheduled_time" value="{{ old('scheduled_time', $template->scheduled_time ? \Carbon\Carbon::parse($template->scheduled_time)->format('H:i') : '') }}" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div>
                            <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Durée (min)</label>
                            <input type="number" name="duration_minutes" value="{{ old('duration_minutes', $template->duration_minutes) }}" min="5" max="480" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none text-center">
                        </div>
                        <div>
                            <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Priorité</label>
                            <select name="priority" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                                @foreach(['basse', 'normale', 'haute', 'critique'] as $p)
                                    <option value="{{ $p }}" {{ $template->priority === $p ? 'selected' : '' }}>{{ ucfirst($p) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- JOURS --}}
                    <div>
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-2">Jours d'exécution</label>
                        <div class="flex gap-2">
                            @php $activeDays = $template->days_of_week ?? []; @endphp
                            @foreach(['1' => 'Lun', '2' => 'Mar', '3' => 'Mer', '4' => 'Jeu', '5' => 'Ven', '6' => 'Sam', '7' => 'Dim'] as $n => $label)
                            <label class="cursor-pointer">
                                <input type="checkbox" name="days_of_week[]" value="{{ $n }}" {{ in_array((int)$n, $activeDays) ? 'checked' : '' }} class="hidden peer">
                                <div class="w-12 h-10 rounded-xl flex items-center justify-center text-[9px] font-black uppercase bg-slate-50 text-slate-400 peer-checked:bg-indigo-500 peer-checked:text-white transition-all shadow-inner">{{ $label }}</div>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- OPTIONS --}}
                    <div class="grid grid-cols-2 gap-4">
                        <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl cursor-pointer">
                            <input type="checkbox" name="per_building" value="1" {{ $template->per_building ? 'checked' : '' }} class="rounded text-indigo-500">
                            <span class="text-[9px] font-black text-slate-600 uppercase">Par bâtiment actif</span>
                        </label>
                        <div>
                            <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Types de lots</label>
                            <div class="flex flex-wrap gap-2">
                                @php $batchTypes = $template->batch_types ?? []; @endphp
                                @foreach(['ponte', 'chair', 'reproducteur', 'poussiniere'] as $t)
                                <label class="cursor-pointer">
                                    <input type="checkbox" name="batch_types[]" value="{{ $t }}" {{ in_array($t, $batchTypes) ? 'checked' : '' }} class="hidden peer">
                                    <div class="px-3 py-1.5 rounded-lg text-[8px] font-black uppercase bg-slate-50 text-slate-400 peer-checked:bg-purple-100 peer-checked:text-purple-600 transition-all">{{ $t }}</div>
                                </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">Description</label>
                        <textarea name="description" rows="2" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-bold shadow-inner outline-none">{{ old('description', $template->description) }}</textarea>
                    </div>
                </div>

                <div class="flex gap-4">
                    <a href="{{ route('tasks.templates') }}" class="flex-1 bg-white border border-slate-200 py-4 rounded-xl text-center text-[9px] font-black uppercase tracking-widest text-slate-400 hover:bg-slate-50 no-underline">Annuler</a>
                    <button type="submit" class="flex-[2] bg-slate-900 text-white py-4 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-indigo-600 border-none cursor-pointer shadow-lg italic">
                        <i class="fa-solid fa-save mr-1"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
