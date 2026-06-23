<x-app-layout>
    @php $currency = setting('general.currency', 'GNF'); @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-amber-500 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-wheat-awn text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Saisir une récolte") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ $cycle->crop_name }} · {{ $cycle->plot?->name }}</p>
                </div>
            </div>
            <a href="{{ route('crop-cycles.show', $cycle) }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
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

            <form action="{{ route('crop-cycles.harvests.store', $cycle) }}" method="POST" x-data="{ sync: false, unit: '{{ old('unit', 'kg') }}' }" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Date de récolte *") }}</label>
                        <input type="date" name="harvest_date" value="{{ old('harvest_date', now()->toDateString()) }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Quantité *") }}</label>
                        <div class="flex gap-2">
                            <input type="number" step="0.001" min="0.001" name="quantity" value="{{ old('quantity') }}" required class="w-2/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                            <input type="text" name="unit" x-model="unit" value="{{ old('unit', 'kg') }}" class="w-1/3 bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-center" placeholder="kg">
                        </div>
                    </div>
                    {{-- Poids net pesé : déduit automatiquement si la quantité est
                         en kg ; à saisir si l'unité est autre (caisses, sacs…)
                         pour garder le rendement kg/ha exact. --}}
                    <div x-show="unit.trim().toLowerCase() !== 'kg'" x-cloak>
                        <label class="block text-[9px] font-black text-amber-500 uppercase ml-2 mb-1 italic">{{ __("Poids net pesé (kg)") }}</label>
                        <input type="number" step="0.001" min="0" name="net_weight_kg" value="{{ old('net_weight_kg') }}" class="w-full bg-amber-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right" placeholder="0.000">
                        <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">{{ __("Pour le calcul du rendement kg/ha") }}</p>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Pertes") }}</label>
                        <input type="number" step="0.001" min="0" name="loss_quantity" value="{{ old('loss_quantity', 0) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Qualité") }}</label>
                        <select name="quality" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-green-700 shadow-inner italic appearance-none cursor-pointer">
                            @foreach($qualities as $q)
                                <option value="{{ $q }}" @selected(old('quality') == $q)>{{ ucfirst($q) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Responsable") }}</label>
                        <select name="employee_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                            <option value="">{{ __("-- Aucun --") }}</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected(old('employee_id') == $emp->id)>{{ $emp->first_name }} {{ $emp->last_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Prix unitaire") }} ({{ $currency }})</label>
                        <input type="number" step="1" min="0" name="unit_price" value="{{ old('unit_price') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                    </div>
                </div>

                <div class="bg-slate-50 rounded-[2rem] p-6 shadow-inner space-y-4">
                    <div class="flex items-center gap-3">
                        <input type="hidden" name="sync_to_stock" value="0">
                        <input type="checkbox" name="sync_to_stock" value="1" id="sync_to_stock" x-model="sync" class="rounded">
                        <label for="sync_to_stock" class="text-[9px] font-black text-slate-500 uppercase italic cursor-pointer">{{ __("Intégrer au stock (Récoltes)") }}</label>
                    </div>
                    <div x-show="sync" x-cloak>
                        <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Nom de l'article en stock") }}</label>
                        <input type="text" name="stock_item_name" value="{{ old('stock_item_name', $cycle->crop_name) }}" class="w-full bg-white border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                    </div>
                </div>

                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]">{{ old('notes') }}</textarea>
                </div>
                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-wheat-awn mr-2 text-amber-400"></i> {{ __("Enregistrer la récolte") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
