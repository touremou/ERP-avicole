<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <x-back :to="route('slaughter.dashboard')" />
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Ordre d'Abattage") }}</h2>
                <p class="text-[10px] font-black text-rose-600 uppercase tracking-[0.2em] mt-2 italic">{{ __("Toutes espèces — Chair, Réformes, Poissons...") }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left" x-data="orderForm()" x-cloak>

            {{-- 🔒 SÉCURITÉ : Vérification de la permission de Création --}}
            @can('abattoir.C')
                @if($errors->any())
                    <div class="mb-8 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('slaughter.orders.store') }}">
                    @csrf
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                        <div class="space-y-6">

                            {{-- LOT SOURCE — tous types --}}
                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Lot source *") }}</label>
                                <select name="batch_id" x-model="selectedBatch" @change="onBatchChange()" required
                                    class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                    <option value="">{{ __("— Sélectionner le lot —") }}</option>

                                    {{-- Groupé par espèce, multiespèces (volailles, ruminants, lapins, porcins, poissons...) --}}
                                    @php
                                        $grouped = $batches->groupBy(fn($b) => $b->species?->slug ?? 'poulet');
                                        $speciesIcons = [
                                            'poulet' => '🍗', 'dinde' => '🦃', 'caille' => '🐦', 'pigeon' => '🐦',
                                            'pintade' => '🐓', 'canard' => '🦆',
                                            'mouton' => '🐑', 'chevre' => '🐐', 'vache' => '🐄',
                                            'lapin' => '🐇', 'porc' => '🐖',
                                            'tilapia' => '🐟', 'carpe' => '🐟', 'silure' => '🐟',
                                        ];
                                        $reformTypes = ['ponte', 'reproducteur', 'laitiere'];
                                    @endphp

                                    @foreach($grouped as $speciesSlug => $batchesForSpecies)
                                        @php
                                            $speciesLabel = $batchesForSpecies->first()->species?->name_fr ?? 'Poulet';
                                            $unitLabel = $batchesForSpecies->first()->species?->unit_label ?? __('sujets');
                                            $icon = $speciesIcons[$speciesSlug] ?? '🐾';
                                        @endphp
                                        <optgroup label="{{ $icon }} {{ mb_strtoupper($speciesLabel) }}">
                                            @foreach($batchesForSpecies as $b)
                                                @php
                                                    $typeLabel = $b->productionType?->name_fr ?? ucfirst($b->type ?? '');
                                                    $isReform = in_array($b->type, $reformTypes, true);
                                                @endphp
                                                <option value="{{ $b->id }}"
                                                    data-qty="{{ $b->current_quantity }}"
                                                    data-type="{{ $b->type }}"
                                                    data-species="{{ $speciesSlug }}"
                                                    data-species-label="{{ $speciesLabel }}"
                                                    data-type-label="{{ $typeLabel }}"
                                                    data-reform="{{ $isReform ? '1' : '0' }}"
                                                    data-building="{{ $b->building->name ?? '—' }}">
                                                    {{ $b->code }} — {{ $b->building->name ?? '' }} ({{ $b->current_quantity }} {{ $unitLabel }}){{ $isReform ? ' — '.__('RÉFORME') : '' }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>

                            {{-- INFO LOT SÉLECTIONNÉ --}}
                            <div x-show="maxQty > 0" class="p-5 rounded-2xl border"
                                 :class="isReform ? 'bg-amber-50 border-amber-200' : 'bg-blue-50 border-blue-200'">
                                <div class="grid grid-cols-3 gap-4 text-center">
                                    <div>
                                        <p class="text-[8px] font-black uppercase" :class="isReform ? 'text-amber-500' : 'text-blue-500'">{{ __("Espèce / Type") }}</p>
                                        <p class="text-sm font-black text-slate-900 uppercase" x-text="speciesLabel + ' — ' + typeLabel"></p>
                                    </div>
                                    <div>
                                        <p class="text-[8px] font-black text-slate-400 uppercase">{{ __("Bâtiment") }}</p>
                                        <p class="text-sm font-black text-slate-900" x-text="building"></p>
                                    </div>
                                    <div>
                                        <p class="text-[8px] font-black uppercase" :class="plannedQty > maxQty ? 'text-red-500' : 'text-emerald-500'">{{ __("Disponible") }}</p>
                                        <p class="text-sm font-black" :class="plannedQty > maxQty ? 'text-red-600' : 'text-emerald-600'" x-text="maxQty + ' sujets'"></p>
                                    </div>
                                </div>

                                {{-- Estimation de rendement carcasse : disponible uniquement pour les
                                     volailles en réforme (settings dédiés). Pour les autres espèces en
                                     réforme, un avertissement générique est affiché sans chiffre. --}}
                                <div x-show="isReform" class="mt-3 p-3 bg-white/50 rounded-xl">
                                    <p class="text-[8px] font-black text-amber-600 uppercase text-center">
                                        <i class="fa-solid fa-info-circle mr-1"></i>
                                        <template x-if="batchSpecies === 'poulet' && batchType === 'ponte'">
                                            <span>{{ __("Pondeuses en fin de cycle — Rendement carcasse estimé :") }} <span x-text="yieldPonte"></span>%</span>
                                        </template>
                                        <template x-if="batchSpecies === 'poulet' && batchType === 'reproducteur'">
                                            <span>{{ __("Reproducteurs en réforme — Rendement carcasse estimé :") }} <span x-text="yieldRepro"></span>%</span>
                                        </template>
                                        <template x-if="batchSpecies !== 'poulet'">
                                            <span x-text="speciesLabel + ' ' + typeLabel.toLowerCase() + ' — {{ __('lot en réforme : rendement carcasse généralement inférieur à un lot standard') }}'"></span>
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Quantité à abattre *") }}</label>
                                    <input type="number" name="planned_quantity" x-model.number="plannedQty" min="1" :max="maxQty || 99999" required
                                        class="w-full rounded-2xl p-4 text-lg font-black shadow-inner outline-none text-center border-2"
                                        :class="plannedQty > maxQty && maxQty > 0 ? 'bg-red-50 border-red-300 text-red-600' : 'bg-slate-50 border-transparent text-slate-800'">

                                    {{-- Alerte dépassement --}}
                                    <div x-show="plannedQty > maxQty && maxQty > 0" class="p-2 bg-red-50 rounded-xl mt-1">
                                        <p class="text-[8px] font-black text-red-600 text-center">
                                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                            {{ __("Max :") }} <span x-text="maxQty"></span> {{ __("sujets disponibles") }}
                                        </p>
                                    </div>

                                    {{-- Boutons rapides --}}
                                    <div class="flex gap-2 mt-2" x-show="maxQty > 0">
                                        <button type="button" @click="plannedQty = maxQty" class="flex-1 bg-slate-100 text-slate-600 py-2 rounded-xl text-[8px] font-black uppercase border-none cursor-pointer hover:bg-slate-200 transition-all">{{ __("Tout le lot") }}</button>
                                        <button type="button" @click="plannedQty = Math.ceil(maxQty / 2)" class="flex-1 bg-slate-100 text-slate-600 py-2 rounded-xl text-[8px] font-black uppercase border-none cursor-pointer hover:bg-slate-200 transition-all">{{ __("Moitié") }}</button>
                                        <button type="button" @click="plannedQty = Math.min(100, maxQty)" class="flex-1 bg-slate-100 text-slate-600 py-2 rounded-xl text-[8px] font-black uppercase border-none cursor-pointer hover:bg-slate-200 transition-all">100</button>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    {{-- Remarque : Input natif HTML de type 'date', ne prend que YYYY-MM-DD. Le setting() format n'est pas applicable ici --}}
                                    <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Date prévue *") }}</label>
                                    <input type="date" name="planned_date" value="{{ now()->toDateString() }}" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Client (si sur commande)") }}</label>
                                <select name="client_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black uppercase shadow-inner outline-none">
                                    <option value="">{{ __("Abattage standard (stock)") }}</option>
                                    @foreach($clients as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <textarea name="notes" rows="2" placeholder="{{ __("Instructions spéciales...") }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none"></textarea>
                        </div>
                    </div>

                    <button type="submit" :disabled="(plannedQty > maxQty && maxQty > 0) || !selectedBatch"
                        :class="((plannedQty > maxQty && maxQty > 0) || !selectedBatch) ? 'bg-slate-300 cursor-not-allowed' : 'bg-rose-500 hover:bg-rose-600 cursor-pointer'"
                        class="w-full text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] transition-all shadow-2xl italic border-none">
                        <i class="fa-solid fa-clipboard-list mr-2"></i>
                        <span x-text="plannedQty > maxQty && maxQty > 0 ? 'QUANTITÉ INSUFFISANTE' : (!selectedBatch ? 'SÉLECTIONNER UN LOT' : 'Créer l\'Ordre d\'Abattage')"></span>
                    </button>
                </form>
            @else
                {{-- ACCÈS REFUSÉ --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center">
                    <i class="fa-solid fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">Accès Restreint</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">Vous n'avez pas la permission de planifier un abattage.</p>
                </div>
            @endcan

        </div>
    </div>

    <script>
    function orderForm() {
        // ⚙️ INJECTION DYNAMIQUE DES SETTINGS
        const yieldPonte = "{{ setting('abattoir.yield_ponte_est', '60-65') }}";
        const yieldRepro = "{{ setting('abattoir.yield_repro_est', '55-65') }}";

        return {
            selectedBatch: '', plannedQty: 0, maxQty: 0,
            batchType: '', batchSpecies: '', speciesLabel: '', typeLabel: '', building: '', isReform: false,
            yieldPonte: yieldPonte,
            yieldRepro: yieldRepro,

            onBatchChange() {
                const sel = document.querySelector('select[name="batch_id"]');
                const opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.value) {
                    this.maxQty = 0; this.batchType = ''; this.batchSpecies = '';
                    this.speciesLabel = ''; this.typeLabel = ''; this.building = ''; this.isReform = false;
                    return;
                }
                this.maxQty = parseInt(opt.dataset.qty) || 0;
                this.batchType = opt.dataset.type || '';
                this.batchSpecies = opt.dataset.species || '';
                this.speciesLabel = opt.dataset.speciesLabel || '—';
                this.typeLabel = opt.dataset.typeLabel || '—';
                this.building = opt.dataset.building || '—';
                this.isReform = opt.dataset.reform === '1';
                this.plannedQty = 0;
            },
        }
    }
    </script>
</x-app-layout>