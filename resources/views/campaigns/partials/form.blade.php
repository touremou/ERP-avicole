{{--
    Formulaire partagé création/édition d'une campagne saisonnière.
    Variables : $campaign (nullable), $nextEidDates (array de dates).
--}}
@php
    $families = [
        'petit_ruminant' => '🐑 Ovins / Caprins',
        'grand_ruminant' => '🐄 Bovins',
        'volaille'       => '🐔 Volaille',
        'aquaculture'    => '🐟 Pisciculture',
        'porcin'         => '🐷 Porcins',
        'lagomorphe'     => '🐰 Lapins',
    ];
    $c = $campaign ?? null;
@endphp

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <div class="lg:col-span-2 space-y-8">
        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100">
            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-8 italic leading-none">01. Identification</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="md:col-span-2">
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Nom de la campagne *</label>
                    <input type="text" name="name" value="{{ old('name', $c->name ?? '') }}" required placeholder="Ex : Tabaski 2026 — Moutons Djallonké"
                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Type *</label>
                    <select name="type" required class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-emerald-600 shadow-inner appearance-none italic outline-none">
                        @foreach(\App\Models\Campaign::TYPES as $val => $label)
                            <option value="{{ $val }}" {{ old('type', $c->type ?? 'tabaski') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Espèce ciblée *</label>
                    <select name="target_family" required class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-emerald-600 shadow-inner appearance-none italic outline-none">
                        @foreach($families as $val => $label)
                            <option value="{{ $val }}" {{ old('target_family', $c->target_family ?? 'petit_ruminant') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Statut *</label>
                    <select name="status" required class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-emerald-600 shadow-inner appearance-none italic outline-none">
                        @foreach(\App\Models\Campaign::STATUSES as $val => $label)
                            <option value="{{ $val }}" {{ old('status', $c->status ?? 'preparation') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Début (achat / mise en place)</label>
                    <input type="date" name="start_date" value="{{ old('start_date', isset($c) && $c->start_date ? $c->start_date->toDateString() : '') }}"
                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Date du pic de vente (fête) *</label>
                    <input type="date" id="target_date" name="target_date" value="{{ old('target_date', isset($c) && $c->target_date ? $c->target_date->toDateString() : '') }}" required
                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                    @if(!empty($nextEidDates))
                        <div class="flex flex-wrap gap-2 mt-3">
                            <span class="text-[8px] font-black text-slate-400 uppercase self-center">Tabaski à venir :</span>
                            @foreach($nextEidDates as $d)
                                <button type="button" onclick="document.getElementById('target_date').value='{{ $d }}'"
                                        class="px-3 py-1.5 bg-emerald-50 text-emerald-600 rounded-xl text-[9px] font-black uppercase border-none cursor-pointer hover:bg-emerald-100 transition-all">
                                    {{ \Carbon\Carbon::parse($d)->translatedFormat('d M Y') }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100">
            <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-8 italic leading-none">02. Objectifs (la marge se joue ici)</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <label class="block text-[10px] font-black text-emerald-500 uppercase mb-2 ml-1 italic leading-none">Objectif têtes</label>
                    <input type="number" name="target_head_count" min="0" value="{{ old('target_head_count', $c->target_head_count ?? '') }}" placeholder="0"
                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-2xl text-slate-800 shadow-inner italic outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase mb-2 ml-1 italic leading-none">Budget achat (GNF)</label>
                    <input type="number" name="target_budget" min="0" value="{{ old('target_budget', $c->target_budget ?? '') }}" placeholder="0"
                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-amber-500 uppercase mb-2 ml-1 italic leading-none">Prix vente cible / tête (GNF)</label>
                    <input type="number" name="target_sale_price" min="0" value="{{ old('target_sale_price', $c->target_sale_price ?? '') }}" placeholder="0"
                           class="w-full p-4 bg-slate-50 rounded-2xl border-none font-black text-slate-700 shadow-inner italic outline-none">
                </div>
            </div>
            <div class="mt-6">
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic">Notes</label>
                <textarea name="notes" rows="2" placeholder="Informations complémentaires..." class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none italic">{{ old('notes', $c->notes ?? '') }}</textarea>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl">
            <h3 class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-4 italic leading-none">🐑 Logique Tabaski</h3>
            <p class="text-[11px] text-slate-300 leading-relaxed font-bold italic">
                Achat groupé en amont, engraissement de 60 à 90 jours, vente groupée au pic.
                Rattachez vos lots ovins/caprins à la campagne pour suivre la marge en temps réel.
            </p>
        </div>
        <button type="submit" class="w-full bg-emerald-600 text-white font-black py-8 rounded-[2rem] hover:bg-emerald-700 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
            <i class="fas fa-floppy-disk mr-2"></i> Enregistrer
        </button>
        <a href="{{ route('campaigns.index') }}" class="block w-full bg-white border border-slate-200 text-slate-400 font-black py-6 rounded-[2rem] hover:bg-red-50 hover:text-red-500 transition-all text-center uppercase tracking-[0.2em] text-[9px] italic no-underline">
            <i class="fas fa-times mr-1"></i> Annuler
        </a>
    </div>
</div>
