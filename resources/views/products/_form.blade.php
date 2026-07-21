@php $product = $product ?? null; @endphp
{{-- COHÉRENCE D'UNITÉ : quand un stock est lié, l'unité de l'article SUIT
     celle du stock (champ synchronisé + verrouillé) — le serveur la force
     de toute façon à la sauvegarde (Product::booted). --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-5"
     x-data="{
         stockUnits: {{ isset($stocks) ? Js::from($stocks->pluck('unit', 'id')) : '{}' }},
         stockId: {{ Js::from((string) old('stock_id', $product->stock_id ?? '')) }},
         unit: {{ Js::from(old('unit', $product->unit ?? 'unite')) }},
         get lockedUnit() { return this.stockId && this.stockUnits[this.stockId] ? this.stockUnits[this.stockId] : null; },
         syncUnit() { if (this.lockedUnit) this.unit = this.lockedUnit; },
     }"
     x-init="syncUnit()">
    <div class="md:col-span-2">
        <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Nom de l\'article *') }}</label>
        <input type="text" name="name" required value="{{ old('name', $product->name ?? '') }}" placeholder="{{ __('Ex: Œuf calibre L (alvéole)') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xs uppercase shadow-inner outline-none italic">
    </div>

    <div>
        <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Catégorie *') }}</label>
        <select name="product_type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xs uppercase shadow-inner outline-none cursor-pointer">
            @foreach($types as $key => $label)
                <option value="{{ $key }}" {{ old('product_type', $product->product_type ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Unité *') }}</label>
        <input type="text" name="unit" required x-model="unit" :readonly="lockedUnit !== null"
               :class="lockedUnit !== null ? 'opacity-60 cursor-not-allowed' : ''"
               class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xs uppercase shadow-inner outline-none italic">
        <p class="text-[8px] text-teal-600 font-black uppercase mt-1 ml-1" x-show="lockedUnit !== null" x-cloak>
            <i class="fa-solid fa-link mr-1"></i>{{ __("Unité alignée sur le stock lié") }} (<span x-text="lockedUnit"></span>)
        </p>
    </div>

    <div>
        <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Prix de base *') }} ({{ currency() }})</label>
        <input type="number" name="base_price" min="0" step="1" required value="{{ old('base_price', $product->base_price ?? 0) }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-teal-600 text-center shadow-inner outline-none">
    </div>

    <div>
        <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Référence (SKU)') }}</label>
        <input type="text" name="sku" value="{{ old('sku', $product->sku ?? '') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xs uppercase shadow-inner outline-none italic">
    </div>

    @if(isset($stocks) && $stocks->isNotEmpty())
    <div class="md:col-span-2">
        <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Article de stock lié') }} <span class="text-slate-300 normal-case">({{ __('optionnel — la vente décrémentera ce stock') }})</span></label>
        <select name="stock_id" x-model="stockId" @change="syncUnit()" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xs uppercase shadow-inner outline-none cursor-pointer">
            <option value="">{{ __('Aucun (pas de suivi de stock)') }}</option>
            @foreach($stocks as $s)
                <option value="{{ $s->id }}">{{ $s->item_name }} ({{ $s->category }} · {{ $s->unit }})</option>
            @endforeach
        </select>
    </div>
    @endif

    <div class="md:col-span-2">
        <label class="text-[10px] uppercase text-slate-400 mb-2 block tracking-widest font-black italic">{{ __('Photo (identification au point de vente)') }}</label>
        @if($product?->photo_url)
            <img src="{{ $product->photo_url }}" alt="" class="h-20 w-20 rounded-xl mb-2 object-contain bg-slate-50 p-1">
        @endif
        <input type="file" name="photo" accept="image/*" class="w-full bg-slate-50 border-none rounded-2xl p-3 text-[11px] font-bold shadow-inner outline-none">
    </div>

    <div class="md:col-span-2 flex flex-wrap gap-6">
        <label class="flex items-center gap-2 text-[11px] font-black text-slate-600 uppercase tracking-widest cursor-pointer">
            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $product->is_active ?? true) ? 'checked' : '' }} class="rounded accent-teal-600">
            {{ __('Actif (proposé à la vente)') }}
        </label>
        <label class="flex items-center gap-2 text-[11px] font-black text-slate-600 uppercase tracking-widest cursor-pointer">
            <input type="checkbox" name="is_favorite" value="1" {{ old('is_favorite', $product->is_favorite ?? false) ? 'checked' : '' }} class="rounded accent-amber-500">
            <i class="fa-solid fa-star text-amber-400"></i> {{ __('Favori caisse (1er écran du POS)') }}
        </label>
    </div>
</div>
