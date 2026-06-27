<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    🛒 {{ __("Caisse (POS)") }}
                </h2>
                <p class="text-[10px] font-black text-teal-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ __("Encaissement rapide — vente comptant") }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('pos.report') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-teal-50 hover:text-teal-600 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-receipt"></i> {{ __("Z caisse") }}
                </a>
                <a href="{{ route('commerce.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all no-underline shadow-sm italic">
                    <i class="fa-solid fa-table-columns"></i> {{ __("Tableau de bord") }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

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

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="posView({{ Illuminate\Support\Js::from($products) }}, {{ Illuminate\Support\Js::from($clients) }})">
                    {{-- GRILLE PRODUITS --}}
                    <div class="lg:col-span-2">
                        <input type="text" x-model="search" placeholder="{{ __('Rechercher un produit…') }}"
                               class="w-full mb-4 bg-white border border-slate-100 rounded-2xl p-4 text-xs font-black shadow-sm outline-none focus:ring-2 focus:ring-teal-200">
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <template x-for="p in filteredProducts" :key="p.id">
                                <button type="button" @click="addToCart(p)"
                                        class="bg-white border border-slate-100 rounded-2xl p-3 text-left shadow-sm hover:border-teal-400 hover:shadow-md transition-all overflow-hidden">
                                    <div class="h-20 -mx-3 -mt-3 mb-2 bg-slate-50 flex items-center justify-center overflow-hidden">
                                        <template x-if="p.photo"><img :src="p.photo" class="w-full h-full object-cover" alt=""></template>
                                        <template x-if="!p.photo"><i class="fa-solid fa-box-open text-2xl text-slate-200"></i></template>
                                    </div>
                                    <p class="text-[10px] font-black text-slate-800 uppercase leading-tight truncate" x-text="p.name"></p>
                                    <p class="text-[8px] font-black text-slate-400 uppercase mt-1">
                                        <span x-text="formatMoney(priceFor(p))"></span> / <span x-text="p.unit"></span>
                                    </p>
                                    <p class="text-[8px] font-black mt-1" :class="p.qty === null ? 'text-slate-400' : (p.qty > 0 ? 'text-emerald-500' : 'text-red-500')">
                                        <span x-show="p.qty === null">{{ __("Non suivi") }}</span>
                                        <span x-show="p.qty !== null">{{ __("Stock") }} : <span x-text="p.qty"></span></span>
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
                                        <span class="flex-1"></span>
                                        <input type="number" x-model.number="line.unit_price" min="0" step="1"
                                               class="w-20 bg-white/10 rounded-lg p-1.5 text-right text-xs font-black outline-none border-none text-emerald-300">
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

                        <label class="block text-[8px] font-black uppercase tracking-widest text-slate-500 mb-1">{{ __("Client (optionnel)") }}</label>
                        <select name="client_id" x-model="clientId" @change="applyTier()" class="w-full bg-white/10 rounded-xl p-3 text-[10px] font-black uppercase mb-1 outline-none border-none text-white appearance-none">
                            <option value="">{{ __("Vente comptoir (détaillant)") }}</option>
                            @foreach($clients as $c)
                                <option value="{{ $c['id'] }}" class="text-slate-900">{{ $c['name'] }}</option>
                            @endforeach
                        </select>
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
        document.addEventListener('alpine:init', () => {
            Alpine.data('posView', (products, clients) => ({
                products,
                clients,
                cart: [],
                search: '',
                clientId: '',
                clientPrices: {},   // { product_id: prix } selon le tarif du client sélectionné
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
                get filteredProducts() {
                    const q = this.search.trim().toLowerCase();
                    return q ? this.products.filter(p => p.name.toLowerCase().includes(q)) : this.products;
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
                        this.cart.push({ id: p.id, name: p.name, unit: p.unit, max: (p.qty === null ? Infinity : p.qty), quantity: 1, unit_price: this.priceFor(p) });
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
