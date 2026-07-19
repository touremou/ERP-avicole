<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Clôture de cycle')" :subtitle="$order->order_number . ' — ' . ($order->batch->code ?? '—')" icon="fa-clipboard-check" accent="emerald" :back="route('slaughter.dashboard')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">
            @can('abattoir.M')
                @if($errors->any())
                    <div class="mb-6 p-5 bg-red-50 text-red-700 rounded-[2rem] text-[10px] font-black uppercase tracking-widest border border-red-200">
                        @foreach($errors->all() as $e)<p class="m-0"><i class="fa-solid fa-circle-exclamation mr-2"></i> {{ $e }}</p>@endforeach
                    </div>
                @endif

                {{-- CONTRÔLES AUTOMATIQUES (informatifs) : ce que le système a pu vérifier --}}
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-6">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-4"><i class="fa-solid fa-robot mr-1"></i> {{ __("Contrôles automatiques") }}</p>
                    @php
                        $autoLabels = [
                            'ccp3_recorded'         => __('Relevé CCP 3 (refroidissement) présent'),
                            'byproducts_recorded'   => __('Sous-produits (sang/plumes/viscères) tracés'),
                            'temperatures_recorded' => __('Relevé de température du jour'),
                        ];
                    @endphp
                    @foreach($autoLabels as $key => $label)
                        <div class="flex items-center gap-3 py-2 border-b border-slate-50 last:border-0">
                            <span class="text-sm">{{ $autoChecks[$key] ? '✅' : '⚠️' }}</span>
                            <span class="text-xs font-black {{ $autoChecks[$key] ? 'text-slate-700' : 'text-amber-600' }}">{{ $label }}</span>
                            @unless($autoChecks[$key])<span class="text-[9px] text-amber-500 uppercase tracking-widest ml-auto">{{ __("manquant") }}</span>@endunless
                        </div>
                    @endforeach
                    <p class="text-[9px] text-slate-400 m-0 mt-3">{{ __("Ces contrôles sont indicatifs — un élément manquant n'empêche pas la clôture mais reste tracé au dossier.") }}</p>
                </div>

                <form method="POST" action="{{ route('slaughter.closure.store', $order) }}">
                    @csrf

                    {{-- CONFIRMATIONS OBLIGATOIRES (déchets + plan sanitaire) --}}
                    <div class="bg-emerald-50/50 p-6 rounded-[2.5rem] border border-emerald-100 shadow-sm mb-6 space-y-4">
                        <p class="text-[10px] font-black uppercase tracking-widest text-emerald-600 mb-2"><i class="fa-solid fa-list-check mr-1"></i> {{ __("Checklist de clôture — obligatoire") }}</p>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="waste_evacuated" value="1" @checked(old('waste_evacuated')) class="mt-1 rounded text-emerald-500">
                            <span class="text-xs font-bold text-slate-700">🗑️ {{ __("Déchets (sang, plumes, viscères) évacués vers la zone déchets / équarrissage (circuit séparé).") }}</span>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="zone_cleaned" value="1" @checked(old('zone_cleaned')) class="mt-1 rounded text-emerald-500">
                            <span class="text-xs font-bold text-slate-700">🧽 {{ __("Zones nettoyées et désinfectées (souillée, transfert, propre).") }}</span>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="marche_avant" value="1" @checked(old('marche_avant')) class="mt-1 rounded text-emerald-500">
                            <span class="text-xs font-bold text-slate-700">➡️ {{ __("Marche en avant respectée — les flux souillé et propre ne se sont pas croisés.") }}</span>
                        </label>
                    </div>

                    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm mb-6 space-y-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Destination des déchets — optionnel") }}</label>
                            <input type="text" name="waste_destination" value="{{ old('waste_destination') }}" placeholder="{{ __('Ex. équarrissage, compost, fosse...') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Observations — optionnel") }}</label>
                            <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-bold shadow-inner outline-none">{{ old('notes') }}</textarea>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-700 border-none cursor-pointer shadow-lg italic">
                        <i class="fa-solid fa-clipboard-check mr-1"></i> {{ __("Clôturer le cycle") }}
                    </button>
                </form>
            @endcan
        </div>
    </div>
</x-app-layout>
