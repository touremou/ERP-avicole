<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div class="flex items-center gap-5">
                <div>
                    <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Produits Finis") }}</h2>
                    <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Stock abattoir — Frais, Congelé, Transformé") }}</p>
                </div>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('slaughter.orders.create') }}" class="bg-rose-500 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-rose-600 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-plus mr-1"></i> {{ __("Abattage") }}</a>
                <a href="{{ route('slaughter.transform.form') }}" class="bg-amber-500 text-white px-5 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-amber-600 transition-all shadow-lg italic no-underline"><i class="fa-solid fa-fire mr-1"></i> {{ __("Transformation") }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-10" x-data="fpManager()">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Stock total") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ number_format($kpi['total_kg'], 1) }} <small class="text-xs text-slate-400">kg</small></p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Pièces") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ number_format($kpi['total_pieces']) }}</p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Valeur stock") }}</p>
                    <p class="text-xl font-black text-emerald-600">{{ number_format($kpi['total_value'], 0, ',', '.') }} <small class="text-[8px]">{{ setting('general.currency', 'GNF') }}</small></p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Types") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $kpi['types_count'] }}</p>
                </div>
                <div @class(['p-5 rounded-[2rem] border shadow-sm text-center',
                    'bg-red-50 border-red-200' => $kpi['expired_count'] > 0,
                    'bg-white border-slate-100' => $kpi['expired_count'] === 0])>
                    <p class="text-[8px] font-black text-red-500 uppercase tracking-widest mb-1">{{ __("Périmés") }}</p>
                    <p class="text-2xl font-black {{ $kpi['expired_count'] > 0 ? 'text-red-600 animate-pulse' : 'text-slate-300' }}">{{ $kpi['expired_count'] }}</p>
                </div>
            </div>

            {{-- ALERTES --}}
            @if($expiring->count() > 0)
            <div class="mb-6 p-5 bg-red-50 border border-red-200 rounded-[2rem]">
                <p class="text-[9px] font-black text-red-600 uppercase tracking-widest mb-2"><i class="fa-solid fa-clock mr-1"></i> {{ __("Proches de la péremption") }}</p>
                @foreach($expiring as $ep)
                    <p class="text-[9px] text-red-700">{{ $ep->product_name }} — {{ __("expire le") }} {{ $ep->expiry_date->format(setting('general.date_format', 'd/m/Y')) }} — {{ number_format($ep->current_quantity_kg, 1) }} kg</p>
                @endforeach
            </div>
            @endif

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-5 py-4 text-left">{{ __("Produit") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Type") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Lieu") }}</th>
                            <th class="px-3 py-4 text-right">{{ __("Quantité") }}</th>
                            <th class="px-3 py-4 text-right">{{ __("Prix/kg") }}</th>
                            <th class="px-3 py-4 text-right">{{ __("Valeur") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("Péremption") }}</th>
                            <th class="px-3 py-4 text-center">{{ __("État") }}</th>
                            <th class="px-5 py-4 text-center">{{ __("Actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($products as $fp)
                        <tr @class(['hover:bg-slate-50/50 transition-all',
                            'bg-red-50/30' => $fp->is_expired,
                            'bg-amber-50/30' => $fp->is_expiring_soon && !$fp->is_expired])>
                            <td class="px-5 py-4">
                                <p class="text-xs font-black text-slate-900 uppercase italic">{{ $fp->product_name }}</p>
                                @if($fp->batch_reference)<p class="text-[7px] text-slate-400">{{ __("Réf") }}: {{ $fp->batch_reference }}</p>@endif
                            </td>
                            <td class="px-3 py-4 text-center">
                                <span @class(['text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-blue-50 text-blue-600' => in_array($fp->product_type, ['entier_frais', 'entier_congele']),
                                    'bg-purple-50 text-purple-600' => in_array($fp->product_type, ['cuisse', 'aile', 'poitrine', 'dos']),
                                    'bg-amber-50 text-amber-600' => in_array($fp->product_type, ['fume', 'grille', 'marine']),
                                    'bg-slate-50 text-slate-500' => !in_array($fp->product_type, ['entier_frais','entier_congele','cuisse','aile','poitrine','dos','fume','grille','marine'])])>
                                    {{ $fp->type_label }}
                                </span>
                            </td>
                            <td class="px-3 py-4 text-center">
                                <span @class(['text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-cyan-50 text-cyan-600' => $fp->storage_location === 'frais',
                                    'bg-blue-50 text-blue-600' => $fp->storage_location === 'congele',
                                    'bg-amber-50 text-amber-600' => $fp->storage_location === 'fumoir',
                                    'bg-emerald-50 text-emerald-600' => $fp->storage_location === 'vitrine'])>
                                    {{ $fp->storage_location }}
                                </span>
                            </td>
                            <td class="px-3 py-4 text-right">
                                <p class="text-sm font-black {{ $fp->is_low ? 'text-red-600' : ($fp->current_quantity_kg > 0 ? 'text-slate-900' : 'text-slate-300') }}">{{ number_format($fp->current_quantity_kg, 1) }} kg</p>
                                @if($fp->current_quantity_pieces > 0)<p class="text-[8px] text-slate-400">{{ $fp->current_quantity_pieces }} pcs</p>@endif
                            </td>
                            <td class="px-3 py-4 text-right text-[10px] font-black text-slate-600">
                                {{ $fp->unit_price > 0 ? number_format($fp->unit_price, 0, ',', '.') . ' ' . setting('general.currency', 'GNF') : '—' }}
                            </td>
                            <td class="px-3 py-4 text-right text-[10px] font-black text-emerald-600">
                                @if($fp->unit_price > 0 && $fp->current_quantity_kg > 0)
                                    {{ number_format($fp->current_quantity_kg * $fp->unit_price, 0, ',', '.') }}
                                @else — @endif
                            </td>
                            <td class="px-3 py-4 text-center text-[10px] font-black">
                                @if($fp->expiry_date)
                                    <span class="{{ $fp->is_expired ? 'text-red-600' : ($fp->is_expiring_soon ? 'text-amber-600' : 'text-slate-500') }}">
                                        {{ $fp->expiry_date->format(setting('general.date_format', 'd/m/Y')) }}
                                    </span>
                                @else
                                    <span class="text-slate-300">{{ __("Non défini") }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-4 text-center">
                                @if($fp->is_expired)
                                    <span class="text-[8px] font-black text-red-600 bg-red-50 px-2 py-1 rounded-full animate-pulse">{{ __("PÉRIMÉ") }}</span>
                                @elseif($fp->is_expiring_soon)
                                    <span class="text-[8px] font-black text-amber-600 bg-amber-50 px-2 py-1 rounded-full">{{ __("URGENT") }}</span>
                                @elseif($fp->is_low)
                                    <span class="text-[8px] font-black text-red-600 bg-red-50 px-2 py-1 rounded-full">{{ __("BAS") }}</span>
                                @elseif($fp->current_quantity_kg > 0)
                                    <span class="text-[8px] font-black text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full">{{ __("OK") }}</span>
                                @else
                                    <span class="text-[8px] font-black text-slate-300">{{ __("VIDE") }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-center">
                                {{-- 🔒 SÉCURITÉ : Vérification de la permission de Modification --}}
                                @can('abattoir.M')
                                <div class="flex items-center justify-center gap-1">
                                    {{-- Éditer (prix, péremption, seuil) --}}
                                    <button @click="openEdit({{ $fp->id }}, '{{ addslashes($fp->product_name) }}', {{ $fp->unit_price }}, '{{ $fp->expiry_date?->format('Y-m-d') ?? '' }}', {{ $fp->alert_threshold_kg }}, '{{ $fp->storage_location }}')"
                                        class="w-8 h-8 rounded-lg bg-slate-50 text-slate-400 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __('Modifier') }}">
                                        <i class="fa-solid fa-pen text-[10px]"></i>
                                    </button>
                                    {{-- Transférer au magasin --}}
                                    @if($fp->current_quantity_kg > 0)
                                    <button @click="openTransfer({{ $fp->id }}, '{{ addslashes($fp->product_name) }}', {{ $fp->current_quantity_kg }})"
                                        class="w-8 h-8 rounded-lg bg-slate-50 text-slate-400 hover:bg-emerald-50 hover:text-emerald-600 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __('→ Stock magasin') }}">
                                        <i class="fa-solid fa-arrow-right-from-bracket text-[10px]"></i>
                                    </button>
                                    {{-- Ajustement --}}
                                    <button @click="openAdjust({{ $fp->id }}, '{{ addslashes($fp->product_name) }}', {{ $fp->current_quantity_kg }})"
                                        class="w-8 h-8 rounded-lg bg-slate-50 text-slate-400 hover:bg-amber-50 hover:text-amber-600 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __('Ajuster quantité') }}">
                                        <i class="fa-solid fa-scale-balanced text-[10px]"></i>
                                    </button>
                                    @endif
                                    {{-- Éliminer (périmé) --}}
                                    @if($fp->is_expired || $fp->current_quantity_kg > 0)
                                    <button @click="openDispose({{ $fp->id }}, '{{ addslashes($fp->product_name) }}', {{ $fp->current_quantity_kg }})"
                                        class="w-8 h-8 rounded-lg bg-slate-50 text-slate-400 hover:bg-red-50 hover:text-red-600 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __('Éliminer') }}">
                                        <i class="fa-solid fa-trash text-[10px]"></i>
                                    </button>
                                    @endif
                                </div>
                                @endcan
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="px-8 py-16 text-center">
                                <i class="fa-solid fa-box-open text-slate-200 text-3xl mb-4 block"></i>
                                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucun produit fini") }}</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- LÉGENDE --}}
            <div class="mt-4 flex flex-wrap gap-4 justify-center">
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-pen text-blue-400"></i> {{ __("Modifier") }}</span>
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-arrow-right-from-bracket text-emerald-400"></i> → {{ __("Magasin") }}</span>
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-scale-balanced text-amber-400"></i> {{ __("Ajuster") }}</span>
                <span class="text-[8px] font-black text-slate-400 uppercase flex items-center gap-1"><i class="fa-solid fa-trash text-red-400"></i> {{ __("Éliminer") }}</span>
            </div>
        </div>

        {{-- ═══ MODAL ÉDITION (prix, péremption, seuil) ═══ --}}
        <div x-show="editModal" x-transition class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" x-cloak>
            <div class="bg-white rounded-[2.5rem] w-full max-w-md shadow-2xl p-8 text-left italic font-bold" @click.outside="editModal = false">
                <h3 class="text-lg font-black text-slate-800 uppercase tracking-tighter mb-6" x-text="{{ Js::from(__('Modifier — ')) }} + editName"></h3>
                <form :action="'/slaughter/finished-products/' + editId" method="POST">
                    @csrf @method('PUT')
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Prix / kg") }} ({{ setting('general.currency', 'GNF') }})</label>
                            <input type="number" name="unit_price" x-model="editPrice" min="0" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-right">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date de péremption") }}</label>
                            <input type="date" name="expiry_date" x-model="editExpiry" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Seuil d'alerte (kg)") }}</label>
                            <input type="number" name="alert_threshold_kg" x-model="editThreshold" min="0" step="0.1" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none text-right">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Lieu de stockage") }}</label>
                            <select name="storage_location" x-model="editLocation" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                <option value="frais">{{ __("Frais") }}</option>
                                <option value="congele">{{ __("Congelé") }}</option>
                                <option value="fumoir">{{ __("Fumoir") }}</option>
                                <option value="vitrine">{{ __("Vitrine") }}</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="w-full mt-6 bg-blue-500 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all border-none cursor-pointer italic">
                        <i class="fa-solid fa-save mr-2"></i> {{ __("Enregistrer") }}
                    </button>
                </form>
            </div>
        </div>

        {{-- ═══ MODAL TRANSFERT MAGASIN ═══ --}}
        <div x-show="transferModal" x-transition class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" x-cloak>
            <div class="bg-white rounded-[2.5rem] w-full max-w-md shadow-2xl p-8 text-left italic font-bold" @click.outside="transferModal = false">
                <h3 class="text-lg font-black text-emerald-600 uppercase tracking-tighter mb-2">→ {{ __("Transfert Magasin") }}</h3>
                <p class="text-[9px] text-slate-500 mb-6 normal-case" x-text="transferName + {{ Js::from(__(' — ')) }} + transferMax.toFixed(1) + {{ Js::from(__(' kg disponibles')) }}"></p>
                <form :action="'/slaughter/finished-products/' + transferId + '/transfer'" method="POST">
                    @csrf
                    <div class="space-y-2 mb-4">
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Quantité à transférer (kg)") }}</label>
                        <input type="number" name="quantity_kg" x-model="transferQty" step="0.1" min="0.1" :max="transferMax" required class="w-full bg-emerald-50 border-2 border-emerald-200 rounded-2xl p-4 text-lg font-black text-emerald-600 outline-none text-center">
                    </div>
                    <button type="button" @click="transferQty = transferMax" class="w-full mb-4 bg-slate-100 text-slate-600 py-2 rounded-xl text-[8px] font-black uppercase border-none cursor-pointer hover:bg-slate-200">{{ __("Tout transférer") }}</button>
                    <button type="submit" class="w-full bg-emerald-500 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer italic">
                        <i class="fa-solid fa-arrow-right-from-bracket mr-2"></i> {{ __("Transférer au Stock Magasin") }}
                    </button>
                </form>
            </div>
        </div>

        {{-- ═══ MODAL AJUSTEMENT ═══ --}}
        <div x-show="adjustModal" x-transition class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" x-cloak>
            <div class="bg-white rounded-[2.5rem] w-full max-w-md shadow-2xl p-8 text-left italic font-bold" @click.outside="adjustModal = false">
                <h3 class="text-lg font-black text-amber-600 uppercase tracking-tighter mb-2">{{ __("Ajustement Inventaire") }}</h3>
                <p class="text-[9px] text-slate-500 mb-6 normal-case" x-text="adjustName + {{ Js::from(__(' — Actuel : ')) }} + adjustCurrent.toFixed(1) + ' kg'"></p>
                <form :action="'/slaughter/finished-products/' + adjustId + '/adjust'" method="POST">
                    @csrf
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Nouvelle quantité (kg)") }}</label>
                            <input type="number" name="new_quantity_kg" x-model="adjustNewQty" step="0.1" min="0" required class="w-full bg-amber-50 border-2 border-amber-200 rounded-2xl p-4 text-lg font-black text-amber-600 outline-none text-center">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-red-500 tracking-widest ml-2">{{ __("Raison de l'ajustement") }} *</label>
                            <input type="text" name="reason" required placeholder="{{ __('Inventaire physique, perte, casse...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                        </div>
                    </div>
                    <button type="submit" class="w-full mt-4 bg-amber-500 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-amber-600 transition-all border-none cursor-pointer italic">
                        <i class="fa-solid fa-scale-balanced mr-2"></i> {{ __("Appliquer l'Ajustement") }}
                    </button>
                </form>
            </div>
        </div>

        {{-- ═══ MODAL ÉLIMINATION ═══ --}}
        <div x-show="disposeModal" x-transition class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" x-cloak>
            <div class="bg-white rounded-[2.5rem] w-full max-w-md shadow-2xl p-8 text-left italic font-bold" @click.outside="disposeModal = false">
                <h3 class="text-lg font-black text-red-600 uppercase tracking-tighter mb-2">⚠ {{ __("Élimination") }}</h3>
                <p class="text-[9px] text-slate-500 mb-6 normal-case" x-text="disposeName + {{ Js::from(__(' — ')) }} + disposeQty.toFixed(1) + {{ Js::from(__(' kg seront éliminés')) }}"></p>
                <form :action="'/slaughter/finished-products/' + disposeId + '/dispose'" method="POST">
                    @csrf
                    <div class="p-4 bg-red-50 rounded-2xl mb-4">
                        <p class="text-[9px] font-black text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i> {{ __("Cette action est irréversible. Tout le stock sera mis à zéro.") }}</p>
                    </div>
                    <div class="space-y-2 mb-4">
                        <label class="text-[9px] font-black uppercase text-red-500 tracking-widest ml-2">{{ __("Motif d'élimination") }} *</label>
                        <input type="text" name="reason" required placeholder="{{ __('Périmé, avarié, contamination...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    </div>
                    <button type="submit" class="w-full bg-red-600 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-700 transition-all border-none cursor-pointer italic">
                        <i class="fa-solid fa-trash mr-2"></i> {{ __("Confirmer l'Élimination") }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function fpManager() {
        return {
            // Edit modal
            editModal: false, editId: 0, editName: '', editPrice: 0, editExpiry: '', editThreshold: 0, editLocation: 'frais',
            openEdit(id, name, price, expiry, threshold, location) {
                this.editId = id; this.editName = name; this.editPrice = price; this.editExpiry = expiry; this.editThreshold = threshold; this.editLocation = location; this.editModal = true;
            },
            // Transfer modal
            transferModal: false, transferId: 0, transferName: '', transferMax: 0, transferQty: 0,
            openTransfer(id, name, max) { this.transferId = id; this.transferName = name; this.transferMax = max; this.transferQty = 0; this.transferModal = true; },
            // Adjust modal
            adjustModal: false, adjustId: 0, adjustName: '', adjustCurrent: 0, adjustNewQty: 0,
            openAdjust(id, name, current) { this.adjustId = id; this.adjustName = name; this.adjustCurrent = current; this.adjustNewQty = current; this.adjustModal = true; },
            // Dispose modal
            disposeModal: false, disposeId: 0, disposeName: '', disposeQty: 0,
            openDispose(id, name, qty) { this.disposeId = id; this.disposeName = name; this.disposeQty = qty; this.disposeModal = true; },
        }
    }
    </script>
</x-app-layout>