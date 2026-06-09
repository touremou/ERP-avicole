<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <a href="{{ route('incubations.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm group no-underline">
                <i class="fas fa-chevron-left group-hover:-translate-x-1 transition-transform text-xs"></i>
                <span class="text-[10px] font-black uppercase italic tracking-widest">Couvoir</span>
            </a>
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    🐣 Dispatch Poussins — {{ $incubation->code_incubation }}
                </h2>
                <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-2 italic">
                    {{ $incubation->incubator->name ?? '—' }} | Éclos : {{ $incubation->hatched_chicks ?? 0 }} poussins
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-10" x-data="dispatchForm()" x-cloak>
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-6">

                    {{-- BILAN ÉCLOSION --}}
                    <div class="grid grid-cols-4 gap-3">
                        <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Mis en couveuse</p>
                            <p class="text-2xl font-black text-slate-800">{{ number_format($incubation->eggs_count) }}</p>
                        </div>
                        <div class="bg-white p-5 rounded-[2rem] border border-emerald-100 shadow-sm text-center">
                            <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">Éclos viables</p>
                            <p class="text-2xl font-black text-emerald-600">{{ number_format($incubation->hatched_chicks ?? 0) }}</p>
                        </div>
                        <div class="bg-white p-5 rounded-[2rem] border border-blue-100 shadow-sm text-center">
                            <p class="text-[8px] font-black text-blue-500 uppercase tracking-widest mb-1">Dispatchés</p>
                            <p class="text-2xl font-black text-blue-600">{{ number_format($incubation->chicks_dispatched ?? 0) }}</p>
                        </div>
                        <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                            'bg-amber-50 border-amber-200' => $remaining > 0,
                            'bg-slate-50 border-slate-100' => $remaining === 0])>
                            <p class="text-[8px] font-black uppercase tracking-widest mb-1 {{ $remaining > 0 ? 'text-amber-500' : 'text-slate-400' }}">Restants</p>
                            <p class="text-2xl font-black {{ $remaining > 0 ? 'text-amber-600 animate-pulse' : 'text-slate-300' }}">{{ number_format($remaining) }}</p>
                        </div>
                    </div>

                    {{-- FORMULAIRE DE DISPATCH --}}
                    @if($remaining > 0)
                    <form method="POST" action="{{ route('chick-dispatches.store', $incubation) }}" class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                        @csrf

                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-6 italic">Dispatcher les poussins</h3>

                        {{-- DESTINATION --}}
                        <div class="grid grid-cols-4 gap-3 mb-6">
                            @foreach([
                                'elevage' => ['icon' => 'fa-house', 'label' => 'Démarrage', 'color' => 'blue', 'sub' => 'Créer un lot'],
                                'vente'   => ['icon' => 'fa-coins', 'label' => 'Vente', 'color' => 'emerald', 'sub' => 'Client externe'],
                                'stock'   => ['icon' => 'fa-boxes-stacked', 'label' => 'Stock', 'color' => 'amber', 'sub' => 'Poussins J1'],
                                'perte'   => ['icon' => 'fa-skull', 'label' => 'Perte', 'color' => 'red', 'sub' => 'Non-viables'],
                            ] as $dest => $cfg)
                            <label class="cursor-pointer">
                                <input type="radio" name="destination_type" value="{{ $dest }}" x-model="destination" class="hidden peer">
                                <div class="peer-checked:bg-{{ $cfg['color'] }}-50 peer-checked:border-{{ $cfg['color'] }}-400 peer-checked:text-{{ $cfg['color'] }}-600 bg-slate-50 border-2 border-transparent rounded-2xl p-4 text-center transition-all text-slate-400 hover:bg-slate-100">
                                    <i class="fa-solid {{ $cfg['icon'] }} text-lg mb-1"></i>
                                    <p class="text-[9px] font-black uppercase">{{ $cfg['label'] }}</p>
                                    <p class="text-[7px] font-bold normal-case">{{ $cfg['sub'] }}</p>
                                </div>
                            </label>
                            @endforeach
                        </div>

                        <div class="grid grid-cols-2 gap-6 mb-6">
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-emerald-600 tracking-widest ml-2">Quantité *</label>
                                <input type="number" name="quantity" x-model.number="qty" min="1" max="{{ $remaining }}" required
                                    class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-2xl text-slate-800 shadow-inner italic text-center outline-none">
                                <p class="text-[8px] text-slate-400 ml-2">Max : {{ $remaining }}</p>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">Qualité</label>
                                <select name="quality_grade" class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-xs shadow-inner italic outline-none">
                                    <option value="A">Grade A — Premium</option>
                                    <option value="B">Grade B — Standard</option>
                                    <option value="C">Grade C — Second choix</option>
                                </select>
                            </div>
                        </div>

                        {{-- CHAMPS ÉLEVAGE --}}
                        <div x-show="destination === 'elevage'" x-transition class="mb-6 p-6 bg-blue-50 rounded-2xl border border-blue-200">
                            <p class="text-[9px] font-black text-blue-600 uppercase tracking-widest mb-4"><i class="fa-solid fa-house mr-1"></i> Démarrage en poussinière</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 ml-2">Bâtiment *</label>
                                    <select name="building_id" class="w-full p-3 bg-white rounded-xl border-none font-black text-xs shadow-inner italic outline-none">
                                        <option value="">Sélectionner...</option>
                                        @foreach($buildings as $b)
                                            <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->type }}, cap. {{ $b->capacity }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 ml-2">Responsable</label>
                                    <select name="employee_id" class="w-full p-3 bg-white rounded-xl border-none font-black text-xs shadow-inner italic outline-none">
                                        <option value="">Optionnel</option>
                                        @foreach($employees as $e)
                                            <option value="{{ $e->id }}">{{ $e->first_name }} {{ $e->last_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <p class="text-[8px] text-blue-500 mt-3"><i class="fa-solid fa-magic-wand-sparkles mr-1"></i> Un lot POUS-XXXXXX sera automatiquement créé dans le module Élevage.</p>
                        </div>

                        {{-- CHAMPS VENTE --}}
                        <div x-show="destination === 'vente'" x-transition class="mb-6 p-6 bg-emerald-50 rounded-2xl border border-emerald-200">
                            <p class="text-[9px] font-black text-emerald-600 uppercase tracking-widest mb-4"><i class="fa-solid fa-coins mr-1"></i> Vente client</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 ml-2">Client *</label>
                                    <select name="client_id" class="w-full p-3 bg-white rounded-xl border-none font-black text-xs shadow-inner italic outline-none">
                                        <option value="">Sélectionner...</option>
                                        @foreach($clients as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 ml-2">Prix / poussin (GNF) *</label>
                                    <input type="number" name="unit_price" x-model.number="unitPrice" min="0" placeholder="5 000"
                                        class="w-full p-3 bg-white rounded-xl border-none font-black text-sm shadow-inner italic outline-none text-right">
                                </div>
                            </div>
                            <div class="mt-3 p-3 bg-white/50 rounded-xl text-center">
                                <p class="text-[8px] font-black text-slate-400 uppercase">Total vente</p>
                                <p class="text-lg font-black text-emerald-600" x-text="(qty * unitPrice).toLocaleString('fr-FR') + ' GNF'"></p>
                            </div>
                        </div>

                        {{-- NOTES --}}
                        <div class="mb-6">
                            <textarea name="notes" rows="2" placeholder="Notes (optionnel)..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none italic"></textarea>
                        </div>

                        <button type="submit" :disabled="!destination || qty < 1"
                            :class="(!destination || qty < 1) ? 'bg-slate-300 cursor-not-allowed' : 'bg-slate-900 hover:bg-blue-600 cursor-pointer'"
                            class="w-full text-white font-black py-6 rounded-[2rem] uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none transition-all">
                            <i class="fas fa-paper-plane mr-2"></i> Dispatcher <span x-text="qty"></span> poussins
                        </button>
                    </form>
                    @else
                    <div class="bg-emerald-50 p-10 rounded-[3rem] border border-emerald-200 text-center">
                        <i class="fa-solid fa-check-double text-emerald-400 text-3xl mb-3"></i>
                        <p class="text-[10px] font-black text-emerald-600 uppercase tracking-widest">Tous les poussins ont été dispatchés</p>
                    </div>
                    @endif
                </div>

                {{-- SIDEBAR : HISTORIQUE DES DISPATCHES --}}
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-list-check text-blue-500"></i> Dispatches effectués
                        </h3>

                        @if($dispatches->count() > 0)
                        <div class="space-y-3">
                            @foreach($dispatches as $d)
                            <div @class(['p-4 rounded-2xl border',
                                'bg-blue-50 border-blue-100' => $d->destination_type === 'elevage',
                                'bg-emerald-50 border-emerald-100' => $d->destination_type === 'vente',
                                'bg-amber-50 border-amber-100' => $d->destination_type === 'stock',
                                'bg-red-50 border-red-100' => $d->destination_type === 'perte'])>
                                <div class="flex justify-between items-start mb-1">
                                    <span class="text-[9px] font-black uppercase">{{ $d->destination_label }}</span>
                                    <span class="text-sm font-black text-slate-900">{{ $d->quantity }}</span>
                                </div>
                                <p class="text-[8px] text-slate-400">{{ $d->dispatch_date->format('d/m/Y') }} — Grade {{ $d->quality_grade }}</p>
                                @if($d->total_amount > 0)
                                    <p class="text-[9px] font-black text-emerald-600 mt-1">{{ number_format($d->total_amount, 0, ',', '.') }} GNF</p>
                                @endif
                                @if($d->notes)
                                    <p class="text-[8px] text-slate-400 mt-1 normal-case">{{ $d->notes }}</p>
                                @endif
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-[9px] text-slate-400 text-center py-6">Aucun dispatch enregistré</p>
                        @endif
                    </div>

                    {{-- RÉSUMÉ PAR DESTINATION --}}
                    @if($dispatches->count() > 0)
                    <div class="bg-slate-900 p-6 rounded-[2.5rem] text-white">
                        <h3 class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-4">Répartition</h3>
                        @foreach($dispatches->groupBy('destination_type') as $type => $group)
                        <div class="flex justify-between mb-2">
                            <span class="text-[9px] font-black text-slate-400 uppercase">
                                {{ match($type) { 'elevage' => '🏠 Élevage', 'vente' => '💰 Vente', 'stock' => '📦 Stock', 'perte' => '⚠️ Perte', default => $type } }}
                            </span>
                            <span class="text-sm font-black text-white">{{ number_format($group->sum('quantity')) }}</span>
                        </div>
                        @endforeach
                        @php $totalRevenue = $dispatches->where('destination_type', 'vente')->sum('total_amount'); @endphp
                        @if($totalRevenue > 0)
                        <div class="border-t border-slate-700 mt-3 pt-3 flex justify-between">
                            <span class="text-[9px] font-black text-emerald-400 uppercase">CA Poussins</span>
                            <span class="text-sm font-black text-emerald-400">{{ number_format($totalRevenue, 0, ',', '.') }} GNF</span>
                        </div>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
    function dispatchForm() {
        return { destination: '', qty: 0, unitPrice: 0 }
    }
    </script>
</x-app-layout>
