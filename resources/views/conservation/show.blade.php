@php
    /**
     * Fiche d'un lot en conservation (T2) : l'état du pari, l'historique des
     * contrôles, et le formulaire de contrôle.
     */
    $currency = setting('general.currency', 'GNF');
    $severityClass = [
        'critique'  => 'bg-rose-50 text-rose-600 border-rose-100',
        'attention' => 'bg-amber-50 text-amber-600 border-amber-100',
        'info'      => 'bg-green-50 text-green-600 border-green-100',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$lot->label"
                       :subtitle="__($lot->status_label) . ' · ' . __('ouvert le') . ' ' . $lot->opened_at->format('d/m/Y')"
                       icon="fa-box" accent="amber" :back="route('stored-lots.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold space-y-8">

            @if(session('success'))
                <div class="bg-green-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-rose-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            {{-- ALERTES : ce qui appelle une décision, en tête. --}}
            @foreach($lot->alerts() as $alert)
                <div class="p-5 rounded-[2rem] border {{ $severityClass[$alert['severity']] ?? 'bg-slate-50 text-slate-500 border-slate-100' }}">
                    <p class="text-[10px] font-black uppercase italic tracking-wide">{{ $alert['message'] }}</p>
                </div>
            @endforeach

            {{-- ÉTAT DU LOT --}}
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                @php
                    $stats = [
                        ['label' => __("Quantité restante"), 'value' => number_format((float) $lot->quantity_current, 1, ',', ' ') . ' ' . $lot->unit,
                         'sub' => __("sur") . ' ' . number_format((float) $lot->quantity_initial, 1, ',', ' ')],
                        ['label' => __("Freinte cumulée"), 'value' => number_format($lot->total_shrinkage, 1, ',', ' ') . ' ' . $lot->unit,
                         'sub' => $lot->shrinkage_percent !== null ? number_format($lot->shrinkage_percent, 1, ',', ' ') . ' %' : null],
                        ['label' => __("Capital immobilisé"), 'value' => number_format($lot->value_at_cost, 0, ',', ' '), 'sub' => $currency],
                        ['label' => __("Prix-cible"), 'value' => $lot->target_unit_price !== null ? number_format((float) $lot->target_unit_price, 0, ',', ' ') : '—',
                         'sub' => $currency . '/' . $lot->unit],
                        ['label' => __("Dernier cours constaté"), 'value' => $lot->last_market_price !== null ? number_format((float) $lot->last_market_price, 0, ',', ' ') : '—',
                         'sub' => $lot->last_checked_at ? $lot->last_checked_at->format('d/m/Y') : __("jamais relevé")],
                    ];
                @endphp
                @foreach($stats as $stat)
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest italic mb-2 leading-tight">{{ $stat['label'] }}</p>
                        <p class="text-lg font-black text-slate-900 leading-none">{{ $stat['value'] }}</p>
                        @if($stat['sub'])<p class="text-[8px] font-bold text-slate-400 uppercase italic mt-2">{{ $stat['sub'] }}</p>@endif
                    </div>
                @endforeach
            </div>

            {{-- VERDICT ÉCONOMIQUE au dernier cours constaté --}}
            @if($lot->margin_at_market !== null)
                <div class="bg-slate-900 text-white p-8 rounded-[3rem] shadow-lg">
                    <p class="text-[9px] font-black text-white/40 uppercase tracking-widest italic mb-3">{{ __("Si je vends aujourd'hui, au cours constaté") }}</p>
                    <p class="text-3xl font-black leading-none {{ $lot->margin_at_market >= 0 ? 'text-green-400' : 'text-rose-400' }}">
                        {{ $lot->margin_at_market >= 0 ? '+' : '' }}{{ number_format($lot->margin_at_market, 0, ',', ' ') }}
                        <small class="text-[11px] opacity-40">{{ $currency }}</small>
                    </p>
                    <p class="text-[9px] font-bold text-white/40 uppercase italic mt-4 leading-relaxed">
                        {{ __("Freinte déjà déduite : la marge porte sur ce qui reste, comparé au capital engagé au départ. Un cours qui monte ne compense pas forcément la perte de poids.") }}
                    </p>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {{-- FORMULAIRE DE CONTRÔLE --}}
                @if($lot->is_open)
                    @can('logistique.C')
                        <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                            <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Nouveau contrôle") }}</h3>
                            <form action="{{ route('stored-lots.checks.store', $lot) }}" method="POST"
                                  x-data="{ condition: 'bon', action: 'aucune',
                                            get needsAction() { return ['insectes','moisissure','degrade'].includes(this.condition) } }"
                                  class="space-y-5">
                                @csrf

                                <div>
                                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">
                                        {{ __("Pesée du lot") }} ({{ $lot->unit }})
                                    </label>
                                    <input type="number" step="0.001" min="0" name="weighed_quantity"
                                           placeholder="{{ number_format((float) $lot->quantity_current, 1, '.', '') }}"
                                           class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                                    <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">
                                        {{ __("La freinte est DÉDUITE de la pesée — on saisit ce qu'on mesure") }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("État constaté *") }}</label>
                                    <select name="condition" x-model="condition" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                                        @foreach($conditions as $key => $label)
                                            <option value="{{ $key }}">{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[9px] font-black uppercase ml-2 mb-1 italic" :class="needsAction ? 'text-rose-500' : 'text-slate-400'">
                                        {{ __("Décision") }}<span x-show="needsAction"> *</span>
                                    </label>
                                    <select name="action_taken" x-model="action" class="w-full border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer"
                                            :class="needsAction ? 'bg-rose-50' : 'bg-slate-50'">
                                        @foreach($actions as $key => $label)
                                            <option value="{{ $key }}">{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                    <p x-show="needsAction && action === 'aucune'" x-cloak class="text-[8px] font-black text-rose-500 uppercase ml-2 mt-1 italic leading-relaxed">
                                        {{ __("Un contrôle qui constate un problème sans rien décider ne protège rien : choisissez une décision.") }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-[9px] font-black text-amber-500 uppercase ml-2 mb-1 italic">
                                        {{ __("Cours du marché du jour") }} ({{ $currency }}/{{ $lot->unit }})
                                    </label>
                                    <input type="number" step="1" min="0" name="market_price"
                                           class="w-full bg-amber-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic text-right">
                                    <p class="text-[8px] font-bold text-slate-400 uppercase ml-2 mt-1 italic">
                                        {{ __("C'est ce relevé qui rend le prix-cible exploitable") }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Contrôleur") }}</label>
                                    <select name="employee_id" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                                        <option value="">{{ __("-- Aucun --") }}</option>
                                        @foreach($employees as $emp)
                                            <option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Observations") }}</label>
                                    <textarea name="notes" rows="2" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-bold text-slate-700 shadow-inner italic text-[11px]"></textarea>
                                </div>

                                <button type="submit" class="w-full bg-slate-900 text-white px-8 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-amber-600 transition-all">
                                    <i class="fa-solid fa-scale-balanced mr-2 text-amber-400"></i> {{ __("Enregistrer le contrôle") }}
                                </button>
                            </form>
                        </div>
                    @endcan
                @endif

                {{-- HISTORIQUE DES CONTRÔLES --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">{{ __("Historique des contrôles") }}</h3>
                    @forelse($lot->checks as $check)
                        <div class="py-3 border-b border-slate-50 last:border-0">
                            <div class="flex items-center justify-between">
                                <p class="text-[11px] font-black uppercase text-slate-800 italic">
                                    {{ $check->checked_at->format('d/m/Y') }}
                                    <span class="text-[8px] px-2 py-0.5 rounded-full ml-2 {{ $check->condition === 'bon' ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600' }}">
                                        {{ __($check->condition_label) }}
                                    </span>
                                </p>
                                @if((float) $check->shrinkage_quantity > 0)
                                    <p class="text-[10px] font-black text-rose-500">−{{ number_format((float) $check->shrinkage_quantity, 1, ',', ' ') }} {{ $lot->unit }}</p>
                                @endif
                            </div>
                            <p class="text-[8px] font-bold text-slate-400 uppercase mt-1">
                                @if($check->weighed_quantity !== null){{ __("pesé") }} {{ number_format((float) $check->weighed_quantity, 1, ',', ' ') }} {{ $lot->unit }} · @endif
                                @if($check->market_price !== null){{ __("cours") }} {{ number_format((float) $check->market_price, 0, ',', ' ') }} · @endif
                                {{ __($check->action_label) }}
                                @if($check->employee) · {{ $check->employee->first_name }} {{ $check->employee->last_name }} @endif
                            </p>
                            @if($check->notes)
                                <p class="text-[9px] font-bold text-slate-500 italic mt-1">{{ $check->notes }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-[10px] font-bold text-slate-300 uppercase italic">{{ __("Aucun contrôle enregistré — le lot n'est pas surveillé.") }}</p>
                    @endforelse
                </div>
            </div>

            {{-- CLÔTURE --}}
            @if($lot->is_open)
                @can('logistique.M')
                    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                        <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-2">{{ __("Clôturer le lot") }}</h3>
                        <p class="text-[9px] font-bold text-slate-400 uppercase italic mb-6 leading-relaxed">
                            {{ __("La clôture ne touche pas l'inventaire : la vente le décrémente par son propre chemin. Doubler la sortie ici ferait disparaître la marchandise deux fois.") }}
                        </p>
                        <form action="{{ route('stored-lots.close', $lot) }}" method="POST" class="flex flex-wrap gap-3 items-end">
                            @csrf
                            <div class="flex-1 min-w-[160px]">
                                <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Issue") }}</label>
                                <select name="status" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic appearance-none cursor-pointer">
                                    <option value="vendu">{{ __("Vendu") }}</option>
                                    <option value="cloture">{{ __("Clôturé (renoncement)") }}</option>
                                </select>
                            </div>
                            <div class="flex-1 min-w-[200px]">
                                <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Motif") }}</label>
                                <input type="text" name="reason" maxlength="255" class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic">
                            </div>
                            <button type="submit" class="bg-slate-100 text-slate-600 px-8 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-slate-200 transition-all">
                                {{ __("Clôturer") }}
                            </button>
                        </form>
                    </div>
                @endcan
            @endif

        </div>
    </div>
</x-app-layout>
