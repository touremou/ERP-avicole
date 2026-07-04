<x-app-layout>
    <x-slot name="header">
        {{-- Pas de :back ici : pos.index est une section de niveau 1, le layout
             rend déjà l'ancre hub (x-hub-back) → :back doublait la flèche. --}}
        <x-page-header :title="'🛒 ' . __('Caisse (POS)')" :subtitle="__('Encaissement rapide — vente comptant')" icon="fa-cash-register" accent="teal">
            <x-slot name="actions">
                <button type="button" onclick="togglePosFullscreen(this)" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all shadow-sm italic cursor-pointer">
                    <i class="fa-solid fa-expand"></i> <span>{{ __("Plein écran") }}</span>
                </button>
                <a href="{{ route('pos.report') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-teal-50 hover:text-teal-600 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-receipt"></i> {{ __("Z caisse") }}
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            @if(! $session)
                {{-- CAISSE FERMÉE → ouverture OBLIGATOIRE via la session avant toute vente.
                     L'ouverture passe par la session (fond de caisse), pas par l'écran POS. --}}
                @can('commerce.C')
                <div class="max-w-md mx-auto bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm text-center not-italic">
                    <div class="w-14 h-14 mx-auto mb-4 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-400 text-xl"><i class="fa-solid fa-lock"></i></div>
                    <h3 class="text-[12px] font-black uppercase tracking-widest text-slate-700 italic">{{ __("Caisse fermée") }}</h3>
                    <p class="text-[10px] font-bold text-slate-400 mt-1 mb-6">{{ __("Ouvrez la caisse (session) pour commencer à encaisser.") }}</p>
                    <form method="POST" action="{{ route('cash-register.open') }}" class="text-left">
                        @csrf
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1 italic">{{ __("Fond de caisse (espèces au départ)") }}</label>
                        <div class="flex gap-3">
                            <input type="number" name="opening_float" min="0" step="1" value="0" required class="flex-1 bg-slate-50 border-none rounded-2xl p-4 text-lg font-black text-slate-800 shadow-inner outline-none text-right">
                            <button type="submit" class="bg-slate-900 text-white px-6 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-teal-600 transition-all border-none cursor-pointer italic"><i class="fa-solid fa-unlock mr-1"></i> {{ __("Ouvrir") }}</button>
                        </div>
                    </form>
                </div>
                @else
                <div class="max-w-md mx-auto bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm text-center text-[10px] font-black text-slate-400 uppercase tracking-widest not-italic">
                    {{ __("Caisse fermée. Demandez l'ouverture d'une session de caisse.") }}
                </div>
                @endcan
            @else
                {{-- SESSION OUVERTE → bandeau + écran de vente --}}
                <div class="mb-6 bg-slate-900 text-white p-4 rounded-[2rem] flex flex-col md:flex-row md:items-center justify-between gap-3 not-italic">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-teal-500/20 rounded-xl flex items-center justify-center text-teal-400"><i class="fa-solid fa-lock-open"></i></div>
                        <div class="text-[10px] font-bold leading-tight">
                            <span class="text-teal-400 font-black uppercase tracking-widest text-[8px]">{{ __("Caisse ouverte") }}</span><br>
                            {{ $session->user?->name ?? '—' }} · {{ __("théorique") }} <span class="font-black">{{ number_format($session->expectedCash(), 0, ',', ' ') }} {{ currency() }}</span>
                        </div>
                    </div>
                    <a href="{{ route('cash-register.index') }}" class="shrink-0 px-4 py-2 bg-white/10 hover:bg-white/20 rounded-xl text-[8px] font-black uppercase tracking-widest transition-all no-underline text-white">{{ __("Clôturer / compter") }}</a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="posView({{ Illuminate\Support\Js::from($products) }}, {{ Illuminate\Support\Js::from($clients) }}, {{ Illuminate\Support\Js::from($sellers) }})">
                    {{-- GRILLE PRODUITS --}}
                    <div class="lg:col-span-2">
                        {{-- Onglets façon balance : favoris / tous les articles / meilleures ventes --}}
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <button type="button" @click="tab = 'fav'" x-show="hasFavorites"
                                    :class="tab === 'fav' ? 'bg-teal-600 text-white' : 'bg-white text-slate-500 border border-slate-200'"
                                    class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all cursor-pointer">
                                <i class="fa-solid fa-star mr-1"></i>{{ __("Favoris") }}
                            </button>
                            <button type="button" @click="tab = 'all'"
                                    :class="tab === 'all' ? 'bg-teal-600 text-white' : 'bg-white text-slate-500 border border-slate-200'"
                                    class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all cursor-pointer">
                                <i class="fa-solid fa-table-cells mr-1"></i>{{ __("Tous") }}
                            </button>
                            <button type="button" @click="tab = 'top'"
                                    :class="tab === 'top' ? 'bg-teal-600 text-white' : 'bg-white text-slate-500 border border-slate-200'"
                                    class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all cursor-pointer">
                                <i class="fa-solid fa-fire mr-1"></i>{{ __("Plus vendus") }}
                            </button>

                            {{-- Saisie code PLU au pavé (Entrée = ajout direct au panier) --}}
                            <div class="ml-auto flex items-center gap-1">
                                <input type="text" inputmode="numeric" x-model="pluCode" @keydown.enter.prevent="enterPlu()"
                                       placeholder="{{ __('PLU') }}" title="{{ __('Code article (PLU) — Entrée pour ajouter au panier') }}"
                                       class="w-24 bg-white border border-slate-200 rounded-xl p-2.5 text-center text-sm font-black shadow-sm outline-none focus:ring-2 focus:ring-teal-200">
                                <button type="button" @click="enterPlu()"
                                        class="px-3 py-2.5 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-teal-600 transition-all cursor-pointer border-none">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                            </div>
                        </div>

                        <input type="text" x-model="search" placeholder="{{ __('Rechercher un produit (nom ou code)…') }}"
                               class="w-full mb-4 bg-white border border-slate-100 rounded-2xl p-4 text-xs font-black shadow-sm outline-none focus:ring-2 focus:ring-teal-200">
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <template x-for="p in filteredProducts" :key="p.id">
                                <button type="button" @click="addToCart(p)"
                                        :disabled="p.qty !== null && p.qty <= 0"
                                        :class="(p.qty !== null && p.qty <= 0) ? 'opacity-50 cursor-not-allowed' : 'hover:border-teal-400 hover:shadow-md'"
                                        class="bg-white border border-slate-100 rounded-2xl p-3 text-left shadow-sm transition-all overflow-hidden relative">
                                    <div class="h-20 -mx-3 -mt-3 mb-2 bg-slate-50 flex items-center justify-center overflow-hidden">
                                        <template x-if="p.photo"><img :src="p.photo" class="w-full h-full object-cover" alt=""></template>
                                        <template x-if="!p.photo"><i class="fa-solid fa-box-open text-2xl text-slate-200"></i></template>
                                    </div>
                                    {{-- Code PLU + étoile favori (façon balance) --}}
                                    <span x-show="p.sku" x-text="p.sku"
                                          class="absolute top-1.5 left-1.5 bg-slate-900/80 text-white text-[8px] font-black px-1.5 py-0.5 rounded-md"></span>
                                    <i x-show="p.fav" class="fa-solid fa-star absolute top-1.5 right-1.5 text-amber-400 text-[10px]"></i>
                                    <p class="text-[10px] font-black text-slate-800 uppercase leading-tight truncate" x-text="p.name"></p>
                                    <p class="text-[8px] font-black text-slate-400 uppercase mt-1">
                                        <span x-text="formatMoney(priceFor(p))"></span> / <span x-text="p.unit"></span>
                                    </p>
                                    <p class="text-[8px] font-black mt-1" :class="p.qty === null ? 'text-slate-400' : (p.qty > 0 ? 'text-emerald-500' : 'text-red-500')">
                                        <span x-show="p.qty === null">{{ __("Non suivi") }}</span>
                                        <span x-show="p.qty !== null && p.qty > 0">{{ __("Stock") }} : <span x-text="p.qty"></span></span>
                                        <span x-show="p.qty !== null && p.qty <= 0">{{ __("Rupture") }}</span>
                                    </p>
                                </button>
                            </template>
                            <p x-show="filteredProducts.length === 0" class="col-span-full text-center text-[10px] font-black text-slate-400 uppercase tracking-widest py-8">
                                {{ __("Aucun article au catalogue.") }}
                                <a href="{{ route('products.index') }}" class="text-teal-500 underline">{{ __("Créer un article") }}</a>
                            </p>
                        </div>
                    </div>

                    {{-- PANIER + ENCAISSEMENT --}}
                    <form method="POST" action="{{ route('pos.checkout') }}" class="bg-slate-900 text-white rounded-[2.5rem] p-6 shadow-2xl self-start sticky top-4">
                        @csrf
                        <h3 class="text-[10px] font-black uppercase tracking-[0.3em] text-slate-500 mb-4">{{ __("Panier") }}</h3>

                        <div class="space-y-2 max-h-72 overflow-y-auto mb-4">
                            <template x-for="(line, i) in cart" :key="line.id">
                                <div class="bg-white/5 rounded-2xl p-3 border border-white/10">
                                    <div class="flex justify-between items-center gap-2">
                                        <p class="text-[10px] font-black uppercase truncate" x-text="line.name"></p>
                                        <button type="button" @click="removeLine(i)" class="text-red-400 hover:text-red-300 text-xs shrink-0"><i class="fa-solid fa-xmark"></i></button>
                                    </div>
                                    <div class="flex items-center gap-2 mt-2">
                                        <button type="button" @click="dec(i)" class="w-7 h-7 bg-white/10 rounded-lg text-white shrink-0">-</button>
                                        <input type="number" x-model.number="line.quantity" min="0.01" step="0.01" :max="line.max"
                                               class="w-14 bg-white/10 rounded-lg p-1.5 text-center text-xs font-black outline-none border-none text-white">
                                        <button type="button" @click="inc(i)" class="w-7 h-7 bg-white/10 rounded-lg text-white shrink-0">+</button>
                                        <span class="text-[8px] text-slate-400" x-text="line.unit"></span>
                                        {{-- Pesée brut − tare (articles au poids, façon balance) --}}
                                        <button type="button" x-show="isWeighable(line)" @click="line.showWeigh = !line.showWeigh"
                                                :class="line.showWeigh ? 'text-amber-300' : 'text-slate-400'"
                                                class="w-7 h-7 bg-white/10 rounded-lg shrink-0 hover:text-amber-300 transition-colors border-none cursor-pointer"
                                                title="{{ __('Peser (brut − tare = net)') }}">
                                            <i class="fa-solid fa-scale-balanced text-[10px]"></i>
                                        </button>
                                        <span class="flex-1"></span>
                                        <input type="number" x-model.number="line.unit_price" min="0" step="1"
                                               class="w-20 bg-white/10 rounded-lg p-1.5 text-right text-xs font-black outline-none border-none text-emerald-300">
                                    </div>
                                    <div x-show="line.showWeigh" x-cloak class="mt-2 bg-amber-500/10 border border-amber-400/20 rounded-xl p-2 flex items-center gap-2">
                                        <input type="number" x-model.number="line.gross" min="0" step="0.005" placeholder="{{ __('Brut kg') }}"
                                               class="w-20 bg-white/10 rounded-lg p-1.5 text-center text-[10px] font-black outline-none border-none text-white placeholder-slate-500">
                                        <span class="text-[10px] text-slate-400 font-black">−</span>
                                        <input type="number" x-model.number="line.tare" min="0" step="0.005" placeholder="{{ __('Tare kg') }}"
                                               class="w-20 bg-white/10 rounded-lg p-1.5 text-center text-[10px] font-black outline-none border-none text-white placeholder-slate-500">
                                        <button type="button" @click="applyWeigh(i)"
                                                class="flex-1 bg-amber-500 hover:bg-amber-400 rounded-lg p-1.5 text-[9px] font-black uppercase text-white transition-all border-none cursor-pointer">
                                            = <span x-text="netOf(line)"></span> kg
                                        </button>
                                    </div>
                                    <p class="text-right text-[10px] font-black text-emerald-300 mt-1" x-text="formatMoney(line.quantity * line.unit_price)"></p>

                                    {{-- Champs soumis --}}
                                    <input type="hidden" :name="`items[${i}][product_id]`" :value="line.id">
                                    <input type="hidden" :name="`items[${i}][quantity]`" :value="line.quantity">
                                    <input type="hidden" :name="`items[${i}][unit_price]`" :value="line.unit_price">
                                </div>
                            </template>
                            <p x-show="cart.length === 0" class="text-center text-[9px] font-black text-slate-500 uppercase tracking-widest py-6">{{ __("Touchez un produit pour l'ajouter") }}</p>
                        </div>

                        <div class="flex justify-between items-center border-t border-white/10 pt-4 mb-4">
                            <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ __("Total") }}</span>
                            <span class="text-2xl font-black text-emerald-400" x-text="formatMoney(total)"></span>
                        </div>

                        {{-- Vendeur nominatif (façon balance : boutons prénom) --}}
                        <template x-if="sellers.length > 0">
                            <div class="mb-4">
                                <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-2">{{ __("Vendeur") }}</label>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="s in sellers" :key="s.id">
                                        <button type="button" @click="sellerId = (sellerId === String(s.id) ? '' : String(s.id))"
                                                :class="sellerId === String(s.id) ? 'bg-teal-500 text-white' : 'bg-white/10 text-slate-300 hover:bg-white/20'"
                                                class="px-3 py-2 rounded-xl text-[9px] font-black uppercase tracking-wide transition-all border-none cursor-pointer"
                                                x-text="s.name"></button>
                                    </template>
                                </div>
                                <input type="hidden" name="seller_employee_id" :value="sellerId">
                            </div>
                        </template>

                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">{{ __("Client (optionnel)") }}</label>
                        <div class="flex gap-2 mb-1">
                            <select name="client_id" x-model="clientId" @change="applyTier()" class="flex-1 bg-white/10 rounded-xl p-3 text-[10px] font-black uppercase outline-none border-none text-white appearance-none">
                                <option value="">{{ __("Vente comptoir (détaillant)") }}</option>
                                <template x-for="c in clients" :key="c.id">
                                    <option :value="c.id" x-text="c.name" class="text-slate-900"></option>
                                </template>
                            </select>
                            <button type="button" @click="showNewClient = !showNewClient" title="{{ __('Nouveau client') }}"
                                    class="shrink-0 px-3 bg-teal-600 hover:bg-teal-500 rounded-xl text-white transition-all border-none cursor-pointer">
                                <i class="fa-solid fa-user-plus text-[11px]"></i>
                            </button>
                        </div>

                        {{-- Création rapide d'un client sans quitter la caisse --}}
                        <div x-show="showNewClient" x-cloak class="bg-white/5 rounded-xl p-3 mb-2 space-y-2">
                            <input x-model="newClient.name" placeholder="{{ __('Nom du client *') }}" class="w-full bg-white/10 rounded-lg p-2 text-[10px] font-black text-white placeholder-slate-500 outline-none border-none">
                            <input x-model="newClient.phone" placeholder="{{ __('Téléphone (optionnel)') }}" class="w-full bg-white/10 rounded-lg p-2 text-[10px] font-black text-white placeholder-slate-500 outline-none border-none">
                            <select x-model="newClient.category" class="w-full bg-white/10 rounded-lg p-2 text-[10px] font-black uppercase text-white outline-none border-none appearance-none">
                                <option value="detaillant" class="text-slate-900">{{ __('Détaillant') }}</option>
                                <option value="grossiste" class="text-slate-900">{{ __('Grossiste') }}</option>
                                <option value="hotel_restaurant" class="text-slate-900">{{ __('Hôtel / Restaurant') }}</option>
                                <option value="revendeur" class="text-slate-900">{{ __('Revendeur') }}</option>
                            </select>
                            <button type="button" @click="addClient()" :disabled="!newClient.name"
                                    class="w-full bg-teal-600 hover:bg-teal-500 disabled:opacity-40 rounded-lg p-2 text-[9px] font-black uppercase tracking-widest text-white transition-all border-none cursor-pointer">
                                <i class="fa-solid fa-check mr-1"></i>{{ __('Enregistrer le client') }}
                            </button>
                        </div>

                        <p class="text-[8px] font-black uppercase tracking-widest text-teal-400 mb-3" x-show="cart.length > 0">{{ __("Tarif") }} : <span x-text="tierLabel"></span></p>

                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">{{ __("Paiement") }}</label>
                        <select name="payment_method" class="w-full bg-white/10 rounded-xl p-3 text-[10px] font-black uppercase mb-5 outline-none border-none text-white appearance-none">
                            <option value="especes" class="text-slate-900">{{ __("Espèces") }}</option>
                            <option value="orange_money" class="text-slate-900">{{ __("Orange Money / MoMo") }}</option>
                            <option value="virement" class="text-slate-900">{{ __("Virement") }}</option>
                            <option value="cheque" class="text-slate-900">{{ __("Chèque") }}</option>
                        </select>

                        <button type="submit" :disabled="cart.length === 0"
                                class="w-full bg-emerald-500 text-white font-black py-5 rounded-[2rem] hover:bg-emerald-400 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-cash-register mr-2"></i> {{ __("Encaisser") }}
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <script>
        // Plein écran POS (comme une caisse dédiée). Bascule + maj de l'icône.
        function togglePosFullscreen() {
            if (! document.fullscreenElement) {
                document.documentElement.requestFullscreen?.();
            } else {
                document.exitFullscreen?.();
            }
        }
        document.addEventListener('fullscreenchange', () => {
            const btn = document.querySelector('[onclick="togglePosFullscreen(this)"]');
            if (! btn) return;
            const on = !! document.fullscreenElement;
            btn.querySelector('i').className = on ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
            const label = btn.querySelector('span');
            if (label) label.textContent = on ? @json(__('Quitter')) : @json(__('Plein écran'));
        });

        document.addEventListener('alpine:init', () => {
            Alpine.data('posView', (products, clients, sellers) => ({
                products,
                clients,
                sellers: sellers || [],
                cart: [],
                search: '',
                clientId: '',
                sellerId: '',
                pluCode: '',
                tab: (products || []).some(p => p.fav) ? 'fav' : 'all',
                clientPrices: {},   // { product_id: prix } selon le tarif du client sélectionné
                showNewClient: false,
                newClient: { name: '', phone: '', category: 'detaillant' },
                addClient() {
                    if (! this.newClient.name) return;
                    fetch('{{ route('pos.clients.store') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body: JSON.stringify(this.newClient),
                    })
                    .then(r => r.ok ? r.json() : null)
                    .then(d => {
                        if (d && d.id) {
                            this.clients.push({ id: d.id, name: d.name });
                            this.clientId = String(d.id);
                            this.showNewClient = false;
                            this.newClient = { name: '', phone: '', category: 'detaillant' };
                            this.applyTier();
                        }
                    })
                    .catch(() => {});
                },
                get tierLabel() {
                    const c = this.clients.find(x => String(x.id) === String(this.clientId));
                    return c ? c.name : '{{ __('Comptoir') }}';
                },
                // Prix appliqué : tarif du client (si chargé) sinon prix par défaut de l'article.
                priceFor(p) {
                    return (this.clientPrices[p.id] != null) ? this.clientPrices[p.id] : p.price;
                },
                applyTier() {
                    // À la sélection d'un client : recharger ses tarifs (groupes de prix
                    // par article) puis réappliquer aux lignes du panier et à l'affichage.
                    const url = '{{ route('sales.catalog-prices') }}' + (this.clientId ? ('?client_id=' + this.clientId) : '');
                    fetch(url, {headers:{'Accept':'application/json'}})
                        .then(r => r.ok ? r.json() : null)
                        .then(d => {
                            this.clientPrices = (d && d.prices) ? d.prices : {};
                            this.cart.forEach(l => { if (this.clientPrices[l.id] != null) l.unit_price = this.clientPrices[l.id]; });
                        })
                        .catch(() => {});
                },
                get hasFavorites() {
                    return this.products.some(p => p.fav);
                },
                get filteredProducts() {
                    const q = this.search.trim().toLowerCase();
                    // La recherche texte (nom OU code PLU) court-circuite l'onglet.
                    if (q) {
                        return this.products.filter(p => p.name.toLowerCase().includes(q)
                            || (p.sku && String(p.sku).toLowerCase().includes(q)));
                    }
                    if (this.tab === 'fav' && this.hasFavorites) {
                        return this.products.filter(p => p.fav);
                    }
                    if (this.tab === 'top') {
                        return [...this.products].filter(p => p.sold > 0)
                            .sort((a, b) => b.sold - a.sold).slice(0, 15);
                    }
                    return this.products;
                },
                // Code PLU tapé au pavé → ajout direct au panier (Entrée).
                enterPlu() {
                    const code = this.pluCode.trim().toLowerCase();
                    if (! code) return;
                    const p = this.products.find(x => x.sku && String(x.sku).toLowerCase() === code);
                    if (p) { this.addToCart(p); this.pluCode = ''; }
                },
                // Pesée façon balance : net = brut − tare, appliqué à la quantité.
                isWeighable(line) {
                    return ['kg', 'KG', 'Kg'].includes(line.unit);
                },
                netOf(line) {
                    const net = Math.max(0, (line.gross || 0) - (line.tare || 0));
                    return Math.round(net * 1000) / 1000;
                },
                applyWeigh(i) {
                    const line = this.cart[i];
                    const net = this.netOf(line);
                    if (net > 0) {
                        line.quantity = Math.min(net, line.max === Infinity ? net : line.max);
                        line.showWeigh = false;
                    }
                },
                get total() {
                    return this.cart.reduce((s, l) => s + (l.quantity || 0) * (l.unit_price || 0), 0);
                },
                addToCart(p) {
                    if (p.qty !== null && p.qty <= 0) return;
                    const existing = this.cart.find(l => l.id === p.id);
                    if (existing) {
                        if (existing.quantity < existing.max) existing.quantity = Math.round((existing.quantity + 1) * 100) / 100;
                    } else {
                        this.cart.push({
                            id: p.id, name: p.name, unit: p.unit,
                            max: (p.qty === null ? Infinity : p.qty),
                            quantity: 1, unit_price: this.priceFor(p),
                            showWeigh: false, gross: null, tare: null,
                        });
                    }
                },
                inc(i) { const l = this.cart[i]; if (l.quantity < l.max) l.quantity = Math.round((l.quantity + 1) * 100) / 100; },
                dec(i) { const l = this.cart[i]; l.quantity = Math.max(0.01, Math.round((l.quantity - 1) * 100) / 100); },
                removeLine(i) { this.cart.splice(i, 1); },
                formatMoney(v) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(Math.round(v || 0)) + ' {{ currency() }}'; },
            }));
        });
    </script>
</x-app-layout>
