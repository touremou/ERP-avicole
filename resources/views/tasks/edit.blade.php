<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <a href="{{ route('tasks.index', ['date' => $task->scheduled_date->toDateString()]) }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">Modifier la tâche</h2>
                <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ $task->title }}</p>
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

            <form method="POST" action="{{ route('tasks.update', $task) }}" class="space-y-6">
                @csrf @method('PUT')

                <div class="bg-white p-8 rounded-2xl border border-slate-100 shadow-sm text-left space-y-5">
                    <div>
                        <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Titre</label>
                        <input type="text" name="title" value="{{ old('title', $task->title) }}" required
                            class="w-full bg-slate-50 border-none rounded-xl p-4 text-sm font-black shadow-inner outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Catégorie</label>
                            <select name="category" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                                @foreach(['alimentation' => '🌾 Alimentation', 'collecte' => '🥚 Collecte', 'controle' => '📋 Contrôle', 'nettoyage' => '🧹 Nettoyage', 'sante' => '💉 Santé', 'maintenance' => '🔧 Maintenance'] as $k => $v)
                                    <option value="{{ $k }}" {{ $task->category === $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Priorité</label>
                            <select name="priority" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                                @foreach(['basse', 'normale', 'haute', 'critique'] as $p)
                                    <option value="{{ $p }}" {{ $task->priority === $p ? 'selected' : '' }}>{{ ucfirst($p) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Date</label>
                            <input type="date" name="scheduled_date" value="{{ old('scheduled_date', $task->scheduled_date->format('Y-m-d')) }}"
                                class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div>
                            <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Heure</label>
                            <input type="time" name="scheduled_time" value="{{ old('scheduled_time', $task->scheduled_time ? \Carbon\Carbon::parse($task->scheduled_time)->format('H:i') : '') }}"
                                class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Employé</label>
                            <select name="employee_id" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                                <option value="">Non assigné</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e->id }}" {{ ($task->employee_id == $e->id) ? 'selected' : '' }}>{{ $e->first_name }} {{ $e->last_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Bâtiment</label>
                            <select name="building_id" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                                <option value="">Aucun</option>
                                @foreach($buildings as $b)
                                    <option value="{{ $b->id }}" {{ ($task->building_id == $b->id) ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Statut</label>
                        <select name="status" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                            <option value="a_faire" {{ $task->status === 'a_faire' ? 'selected' : '' }}>⏳ À faire</option>
                            <option value="en_cours" {{ $task->status === 'en_cours' ? 'selected' : '' }}>🔄 En cours</option>
                            <option value="annule" {{ $task->status === 'annule' ? 'selected' : '' }}>❌ Annulé</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-[9px] font-black text-slate-500 uppercase tracking-widest block mb-2">Description</label>
                        <textarea name="description" rows="3" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-bold shadow-inner outline-none">{{ old('description', $task->description) }}</textarea>
                    </div>
                </div>

                <div class="flex gap-4">
                    <a href="{{ route('tasks.index', ['date' => $task->scheduled_date->toDateString()]) }}"
                       class="flex-1 bg-white border border-slate-200 py-4 rounded-xl text-center text-[9px] font-black uppercase tracking-widest text-slate-400 hover:bg-slate-50 no-underline">Annuler</a>
                    <button type="submit" class="flex-[2] bg-slate-900 text-white py-4 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-blue-600 border-none cursor-pointer shadow-lg italic">
                        <i class="fa-solid fa-save mr-1"></i> Enregistrer
                    </button>
                </div>
            </form>

            {{-- SUPPRIMER --}}
            @can('admin.S')
            <div class="mt-6 text-center">
                <form method="POST" action="{{ route('tasks.destroy', $task) }}" onsubmit="return confirm('Supprimer cette tâche ?')">
                    @csrf @method('DELETE')
                    <button class="text-[9px] font-black text-red-400 hover:text-red-600 uppercase tracking-widest border-none bg-transparent cursor-pointer">
                        <i class="fa-solid fa-trash-can mr-1"></i> Supprimer cette tâche
                    </button>
                </form>
            </div>
            @endcan
        </div>
    </div>
</x-app-layout>
