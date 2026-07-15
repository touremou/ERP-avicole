<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$campaign->name" :subtitle="__('Modifier la campagne')" icon="fa-calendar-week" accent="green" :back="route('crop-campaigns.show', $campaign)" />
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
            <x-flash />

            <form action="{{ route('crop-campaigns.update', $campaign) }}" method="POST" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Code") }}</label>
                        <input type="text" name="code" value="{{ old('code', $campaign->code) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic uppercase">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Année *") }}</label>
                        <input type="number" name="year" min="2020" max="2035" value="{{ old('year', $campaign->year) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de la campagne *") }}</label>
                        <input type="text" name="name" value="{{ old('name', $campaign->name) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Saison *") }}</label>
                        <select name="season" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($seasons as $key => $s)
                                <option value="{{ $key }}" @selected(old('season', $campaign->season) == $key)>{{ $s['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Statut *") }}</label>
                        <select name="status" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($statuses as $key => $label)
                                <option value="{{ $key }}" @selected(old('status', $campaign->status) == $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date début *") }}</label>
                        <input type="date" name="start_date" value="{{ old('start_date', $campaign->start_date?->format('Y-m-d')) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date fin prévue") }}</label>
                        <input type="date" name="end_date_planned" value="{{ old('end_date_planned', $campaign->end_date_planned?->format('Y-m-d')) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Objectif de production (tonnes)") }}</label>
                        <input type="number" step="0.1" min="0" name="target_production_t" value="{{ old('target_production_t', $campaign->target_production_t) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                        <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes', $campaign->notes) }}</textarea>
                    </div>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Enregistrer les modifications") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
