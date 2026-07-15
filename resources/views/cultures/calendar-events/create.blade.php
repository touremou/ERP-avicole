<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Ajouter un événement')" :subtitle="__('Calendrier cultural — événement libre')" icon="fa-calendar-plus" accent="green" :back="route('cultures.dashboard', ['tab' => 'calendar'])" />
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

            <form action="{{ route('crop-calendar-events.store') }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Titre *") }}</label>
                        <input type="text" name="title" value="{{ old('title') }}" required
                            placeholder="{{ __('Traitement herbicide, Irrigation, Observation parcelle…') }}"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Type d'événement *") }}</label>
                        <select name="event_type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Choisir --") }}</option>
                            @foreach($types as $key => $label)
                                <option value="{{ $key }}" @selected(old('event_type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Cycle de culture (optionnel)") }}</label>
                        <select name="crop_cycle_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($cropCycles as $cycle)
                                <option value="{{ $cycle->id }}" @selected(old('crop_cycle_id') == $cycle->id)>{{ $cycle->crop_name }} @if($cycle->code)({{ $cycle->code }})@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de l'événement *") }}</label>
                        <input type="date" name="event_date" value="{{ old('event_date', now()->toDateString()) }}" required
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de fin (optionnel)") }}</label>
                        <input type="date" name="end_date" value="{{ old('end_date') }}"
                            class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Couleur") }}</label>
                        <select name="color" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="green"  @selected(old('color', 'green') === 'green')>{{ __("Vert") }}</option>
                            <option value="blue"   @selected(old('color') === 'blue')>{{ __("Bleu") }}</option>
                            <option value="amber"  @selected(old('color') === 'amber')>{{ __("Ambre") }}</option>
                            <option value="red"    @selected(old('color') === 'red')>{{ __("Rouge") }}</option>
                            <option value="purple" @selected(old('color') === 'purple')>{{ __("Violet") }}</option>
                            <option value="slate"  @selected(old('color') === 'slate')>{{ __("Gris") }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="3" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-calendar-plus mr-2 text-green-400"></i> {{ __("Ajouter l'événement") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
