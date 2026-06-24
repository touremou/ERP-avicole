<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <a href="{{ route('dispatches.show', $dispatch) }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Réception") }}</h2>
                <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-2 italic">
                    {{ __("Expédition") }} {{ $dispatch->dispatch_number }} — {{ $dispatch->destination }}
                </p>
            </div>
        </div>
    </x-slot>

    @php
        // État initial des lignes pour le contrôle live « reçu + endommagé ≤ expédié ».
        // On honore old() pour conserver la saisie après une erreur de validation.
        $oldItems = collect(old('items', []));
        $lineInit = $dispatch->items->values()->map(function ($it, $i) use ($oldItems) {
            $o = $oldItems->get($i, []);
            return [
                'dispatched' => (float) $it->quantity_dispatched,
                'received'   => isset($o['quantity_received']) ? (float) $o['quantity_received'] : (float) $it->quantity_dispatched,
                'damaged'    => isset($o['quantity_damaged'])  ? (float) $o['quantity_damaged']  : 0.0,
            ];
        });
    @endphp

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left"
             x-data="{
                lines: @js($lineInit),
                over(i) { return (Number(this.lines[i].received) + Number(this.lines[i].damaged)) > Number(this.lines[i].dispatched) + 1e-6; },
                get hasError() { return this.lines.some((_, i) => this.over(i)); }
             }" x-cloak>

            @if($errors->any())
                <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}
                </div>
            @endif

            {{-- INFO EXPÉDITION --}}
            <div class="bg-orange-50 p-6 rounded-[2.5rem] border border-orange-200 mb-6">
                <p class="text-[9px] font-black text-orange-600 uppercase tracking-widest mb-2">
                    <i class="fa-solid fa-truck-fast mr-1"></i> {{ __("Expédié par") }} {{ $dispatch->dispatcher->name ?? '—' }} {{ __("le") }} {{ $dispatch->dispatch_date->format('d/m/Y') }}
                </p>
                <p class="text-xs text-slate-600">
                    {{ __("Chauffeur") }} : <strong>{{ $dispatch->driver_name }}</strong> — {{ __("Véhicule") }} : {{ $dispatch->vehicle_plate ?? '—' }}
                </p>
            </div>

            <form method="POST" action="{{ route('dispatches.reception.store', $dispatch) }}">
                @csrf

                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-6">
                    <div class="grid grid-cols-2 gap-6 mb-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date de réception") }}</label>
                            <input type="date" name="reception_date" value="{{ now()->toDateString() }}" required
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Heure d'arrivée") }}</label>
                            <input type="time" name="reception_time" value="{{ now()->format('H:i') }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                </div>

                {{-- LIGNES À RÉCEPTIONNER --}}
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-clipboard-check text-emerald-500"></i> {{ __("Vérification ligne par ligne") }}
                    </h3>

                    @foreach($dispatch->items->values() as $i => $item)
                    <div class="p-5 bg-slate-50 rounded-2xl mb-4 border transition-colors"
                         :class="over({{ $i }}) ? 'border-red-300 bg-red-50/40' : 'border-slate-100'">
                        <input type="hidden" name="items[{{ $i }}][dispatch_item_id]" value="{{ $item->id }}">

                        <div class="flex justify-between items-center mb-4">
                            <div>
                                <p class="text-sm font-black text-slate-900 uppercase">{{ $item->product_name }}</p>
                                <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest">{{ str_replace('_', ' ', $item->product_type) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Qté expédiée") }}</p>
                                <p class="text-xl font-black text-slate-900">{{ $item->quantity_dispatched }} <small class="text-[9px] text-slate-400 uppercase">{{ $item->unit }}</small></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <div class="space-y-1">
                                <label class="text-[8px] font-black uppercase text-emerald-600 tracking-widest">{{ __("Qté reçue") }}</label>
                                <input type="number" name="items[{{ $i }}][quantity_received]" x-model.number="lines[{{ $i }}].received"
                                    step="0.01" min="0" :max="lines[{{ $i }}].dispatched" required
                                    class="w-full bg-white border-2 border-emerald-200 rounded-xl p-3 text-sm font-black text-emerald-600 outline-none text-center focus:border-emerald-500">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[8px] font-black uppercase text-amber-600 tracking-widest">{{ __("Endommagé") }}</label>
                                <input type="number" name="items[{{ $i }}][quantity_damaged]" x-model.number="lines[{{ $i }}].damaged"
                                    step="0.01" min="0" :max="Math.max(0, lines[{{ $i }}].dispatched - lines[{{ $i }}].received)"
                                    class="w-full bg-white border-2 border-amber-200 rounded-xl p-3 text-sm font-black text-amber-600 outline-none text-center focus:border-amber-500">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[8px] font-black uppercase text-slate-400 tracking-widest">{{ __("État arrivée") }}</label>
                                <select name="items[{{ $i }}][condition]" class="w-full bg-white border border-slate-200 rounded-xl p-3 text-[10px] font-black uppercase outline-none appearance-none">
                                    <option value="bon">{{ __("Bon") }}</option>
                                    <option value="endommage">{{ __("Endommagé") }}</option>
                                    <option value="suspect">{{ __("Suspect") }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <input type="text" name="items[{{ $i }}][notes]" placeholder="{{ __("Justification si écart...") }}"
                                class="w-full bg-white border border-slate-200 rounded-xl p-3 text-[10px] font-bold text-slate-600 outline-none">
                        </div>

                        <p x-show="over({{ $i }})" x-cloak class="mt-3 text-[9px] font-black text-red-600 uppercase tracking-widest flex items-center gap-1">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            {{ __("Reçu + endommagé dépasse la quantité expédiée") }}
                            (<span x-text="lines[{{ $i }}].dispatched"></span> {{ $item->unit }}).
                        </p>
                    </div>
                    @endforeach
                </div>

                {{-- NOTES GÉNÉRALES --}}
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-6">
                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Observations générales") }}</label>
                    <textarea name="notes" rows="3" placeholder="{{ __("État du véhicule à l'arrivée, remarques...") }}"
                        class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none mt-2"></textarea>
                </div>

                <p x-show="hasError" x-cloak class="mb-4 p-4 bg-red-50 text-red-700 rounded-2xl text-[10px] font-black uppercase tracking-widest border border-red-200 flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    {{ __("Corrigez les lignes en rouge : reçu + endommagé ne peut pas dépasser l'expédié.") }}
                </p>

                <button type="submit" :disabled="hasError"
                    class="w-full bg-emerald-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-emerald-600 transition-all shadow-2xl italic border-none cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-emerald-500">
                    <i class="fa-solid fa-shield-check mr-2"></i> {{ __("Valider la Réception & Lancer la Réconciliation") }}
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
