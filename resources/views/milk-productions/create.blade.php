<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('🐐 Collecte') . ' — ' . $batch->code" :subtitle="$batch->building?->name"
                       icon="fa-bottle-droplet" accent="cyan" :back="route('milk-productions.index')" />
    </x-slot>

    <div class="py-10 italic font-bold text-slate-700 text-left" x-data="milkForm()">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-8 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
                    </ul>
                </div>
            @endif

            @if($existingToday)
                <div class="mb-6 p-5 bg-amber-50 text-amber-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-amber-200">
                    <i class="fa-solid fa-circle-info mr-1"></i> {{ __("Une collecte existe déjà pour ce lot aujourd'hui — l'enregistrement la mettra à jour.") }}
                </div>
            @endif

            <form method="POST" action="{{ route('milk-productions.store') }}">
                @csrf
                <input type="hidden" name="batch_id" value="{{ $batch->id }}">

                <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 space-y-8">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic">{{ __("Date de collecte *") }}</label>
                        <input type="date" name="production_date" value="{{ old('production_date', $existingToday?->production_date?->toDateString() ?? now()->toDateString()) }}" max="{{ now()->toDateString() }}" required
                               class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic">{{ __("Traite matin (L)") }}</label>
                            <input type="number" step="0.1" min="0" name="morning_liters" x-model.number="morning" value="{{ old('morning_liters', $existingToday?->morning_liters ?? 0) }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-2xl text-slate-800 shadow-inner italic outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic">{{ __("Traite soir (L)") }}</label>
                            <input type="number" step="0.1" min="0" name="evening_liters" x-model.number="evening" value="{{ old('evening_liters', $existingToday?->evening_liters ?? 0) }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-2xl text-slate-800 shadow-inner italic outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black text-amber-500 uppercase mb-2 ml-1 italic">{{ __("Prix / litre") }} ({{ currency() }})</label>
                            <input type="number" step="1" min="0" name="unit_price" x-model.number="price" value="{{ old('unit_price', $existingToday?->unit_price ?? $lastPrice ?? 0) }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                            @if($lastPrice && !$existingToday)
                                <p class="text-[8px] text-slate-300 ml-2 uppercase font-bold mt-1">{{ __("Dernier prix connu pré-rempli") }}</p>
                            @endif
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic">{{ __("Femelles traites") }}</label>
                            <input type="number" step="1" min="0" name="milking_females" value="{{ old('milking_females', $existingToday?->milking_females ?? '') }}" placeholder="{{ __('Optionnel') }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic">{{ __("Notes") }}</label>
                        <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none italic">{{ old('notes', $existingToday?->notes ?? '') }}</textarea>
                    </div>

                    {{-- RÉCAP --}}
                    <div class="bg-slate-900 p-6 rounded-[2rem] text-white flex justify-between items-center">
                        <div>
                            <p class="text-[9px] font-black text-emerald-400 uppercase tracking-widest">{{ __("Total collecté") }}</p>
                            <p class="text-3xl font-black italic" x-text="(morning + evening).toFixed(1) + ' L'"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest">{{ __("Valorisation") }}</p>
                            <p class="text-3xl font-black italic" x-text="fmt((morning + evening) * price) + ' {{ currency() }}'"></p>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 text-white font-black py-6 rounded-[2rem] hover:bg-emerald-700 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                        <i class="fa-solid fa-droplet mr-2"></i> {{ __("Enregistrer la collecte") }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function milkForm() {
        return {
            morning: {{ old('morning_liters', $existingToday?->morning_liters ?? 0) }},
            evening: {{ old('evening_liters', $existingToday?->evening_liters ?? 0) }},
            price: {{ old('unit_price', $existingToday?->unit_price ?? $lastPrice ?? 0) }},
            fmt(v) { return new Intl.NumberFormat('fr-GN', { maximumFractionDigits: 0 }).format(v || 0); },
        }
    }
    </script>
</x-app-layout>
