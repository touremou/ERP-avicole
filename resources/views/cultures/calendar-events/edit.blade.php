<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-calendar-pen text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Modifier l'événement") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ $event->title }}</p>
                </div>
            </div>
            <a href="{{ route('cultures.dashboard', ['tab' => 'calendar']) }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-xmark mr-2"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('crop-calendar-events.update', $event) }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Titre *") }}</label>
                        <input type="text" name="title" value="{{ old('title', $event->title) }}" required
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type d'événement *") }}</label>
                        <select name="event_type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Choisir --") }}</option>
                            @foreach($types as $key => $label)
                                <option value="{{ $key }}" @selected(old('event_type', $event->event_type) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle de culture (optionnel)") }}</label>
                        <select name="crop_cycle_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($cropCycles as $cycle)
                                <option value="{{ $cycle->id }}" @selected(old('crop_cycle_id', $event->crop_cycle_id) == $cycle->id)>{{ $cycle->crop_name }} @if($cycle->code)({{ $cycle->code }})@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de l'événement *") }}</label>
                        <input type="date" name="event_date" value="{{ old('event_date', $event->event_date?->toDateString()) }}" required
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de fin (optionnel)") }}</label>
                        <input type="date" name="end_date" value="{{ old('end_date', $event->end_date?->toDateString()) }}"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Couleur") }}</label>
                        <select name="color" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="green"  @selected(old('color', $event->color) === 'green')>{{ __("Vert") }}</option>
                            <option value="blue"   @selected(old('color', $event->color) === 'blue')>{{ __("Bleu") }}</option>
                            <option value="amber"  @selected(old('color', $event->color) === 'amber')>{{ __("Ambre") }}</option>
                            <option value="red"    @selected(old('color', $event->color) === 'red')>{{ __("Rouge") }}</option>
                            <option value="purple" @selected(old('color', $event->color) === 'purple')>{{ __("Violet") }}</option>
                            <option value="slate"  @selected(old('color', $event->color) === 'slate')>{{ __("Gris") }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="3" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes', $event->notes) }}</textarea>
                </div>
                <div class="flex items-center justify-between pt-4 border-t border-slate-50">
                    <form action="{{ route('crop-calendar-events.destroy', $event) }}" method="POST" class="inline"
                          onsubmit="return confirm('{{ __('Supprimer cet événement ?') }}')">
                        @csrf
                        @method('DELETE')
                        @can('cultures.S')
                        <button type="submit" class="text-[10px] font-black uppercase text-rose-400 hover:text-rose-600 transition italic">
                            <i class="fa-solid fa-trash mr-1"></i> {{ __("Supprimer") }}
                        </button>
                        @endcan
                    </form>
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-floppy-disk mr-2 text-green-400"></i> {{ __("Enregistrer") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
