<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Rectifier collecte') . ' — ' . $batch->code" :subtitle="$batch->building?->name"
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

            <form method="POST" action="{{ route('milk-productions.update', $milk) }}">
                @csrf @method('PUT')
                <input type="hidden" name="batch_id" value="{{ $batch->id }}">

                <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 space-y-8">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic">{{ __("Date de collecte *") }}</label>
                        <input type="date" name="production_date" value="{{ old('production_date', $milk->production_date->toDateString()) }}" max="{{ now()->toDateString() }}" required
                               class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic">{{ __("Traite matin (L)") }}</label>
                            <input type="number" step="0.1" min="0" name="morning_liters" x-model.number="morning" value="{{ old('morning_liters', $milk->morning_liters) }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-2xl text-slate-800 shadow-inner italic outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic">{{ __("Traite soir (L)") }}</label>
                            <input type="number" step="0.1" min="0" name="evening_liters" x-model.number="evening" value="{{ old('evening_liters', $milk->evening_liters) }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-2xl text-slate-800 shadow-inner italic outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black text-amber-500 uppercase mb-2 ml-1 italic">{{ __("Prix / litre") }} ({{ currency() }})</label>
                            <input type="number" step="1" min="0" name="unit_price" x-model.number="price" value="{{ old('unit_price', $milk->unit_price) }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic">{{ __("Femelles traites") }}</label>
                            <input type="number" step="1" min="0" name="milking_females" value="{{ old('milking_females', $milk->milking_females) }}" placeholder="{{ __('Optionnel') }}"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic">{{ __("Notes") }}</label>
                        <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none italic">{{ old('notes', $milk->notes) }}</textarea>
                    </div>

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

                    <div class="flex gap-3">
                        <button type="submit" class="flex-1 bg-emerald-600 text-white font-black py-6 rounded-[2rem] hover:bg-emerald-700 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                            <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Mettre à jour") }}
                        </button>
                    </div>
                </div>
            </form>

            @can('production.S')
            <form method="POST" action="{{ route('milk-productions.destroy', $milk) }}" class="mt-4" onsubmit="return confirm(@json(__('Supprimer cette collecte ?')));">
                @csrf @method('DELETE')
                <button type="submit" class="w-full bg-white border border-red-100 text-red-400 font-black py-4 rounded-[2rem] hover:bg-red-500 hover:text-white transition-all uppercase tracking-[0.2em] text-[9px] italic border-none cursor-pointer">
                    <i class="fa-solid fa-trash mr-1"></i> {{ __("Supprimer cette collecte") }}
                </button>
            </form>
            @endcan
        </div>
    </div>

    <script>
    function milkForm() {
        return {
            morning: {{ old('morning_liters', $milk->morning_liters) }},
            evening: {{ old('evening_liters', $milk->evening_liters) }},
            price: {{ old('unit_price', $milk->unit_price) }},
            fmt(v) { return new Intl.NumberFormat('fr-GN', { maximumFractionDigits: 0 }).format(v || 0); },
        }
    }
    </script>
</x-app-layout>
