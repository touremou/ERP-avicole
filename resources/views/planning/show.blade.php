<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$plan->building->name ?? '—'" :subtitle="ucfirst($plan->batch_type) . ($plan->model_name ? ' — ' . $plan->model_name : '') . ' — ' . number_format($plan->planned_quantity) . ' ' . __('sujets')" icon="fa-calendar-days" accent="indigo" :back="route('planning.index')" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- ═══ TIMELINE VISUELLE ═══ --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6">{{ __("Cycle de la bande") }}</h3>
                @php
                    $steps = [
                        ['date' => $plan->chick_order_deadline, 'label' => __("Commander"), 'icon' => 'fa-phone', 'color' => 'red',
                         'done' => in_array($plan->status, ['commande', 'en_cours', 'termine'])],
                        ['date' => $plan->planned_arrival_date, 'label' => __("Arrivée"), 'icon' => 'fa-truck', 'color' => 'blue',
                         'done' => in_array($plan->status, ['commande', 'en_cours', 'termine'])],
                        ['date' => null, 'label' => __("Activation"), 'icon' => 'fa-rocket', 'color' => 'emerald',
                         'done' => in_array($plan->status, ['en_cours', 'termine'])],
                        ['date' => $plan->planned_end_date, 'label' => $plan->batch_type === 'chair' ? __("Abattage") : __("Réforme"), 'icon' => 'fa-flag-checkered', 'color' => 'amber',
                         'done' => $plan->status === 'termine'],
                        ['date' => $plan->sanitary_void_end, 'label' => __("Bât. libre"), 'icon' => 'fa-broom', 'color' => 'slate',
                         'done' => false],
                    ];
                @endphp
                <div class="flex items-center gap-1 overflow-x-auto pb-2">
                    @foreach($steps as $i => $step)
                        <div class="flex items-center shrink-0">
                            <div @class(['w-12 h-12 rounded-2xl flex items-center justify-center shadow-sm transition-all',
                                "bg-{$step['color']}-500 text-white scale-110" => $step['done'],
                                "bg-{$step['color']}-50 text-{$step['color']}-300" => !$step['done']])>
                                <i class="fa-solid {{ $step['icon'] }} text-sm"></i>
                            </div>
                            <div class="ml-2 mr-3">
                                <p class="text-[8px] font-black uppercase {{ $step['done'] ? 'text-slate-700' : 'text-slate-300' }}">{{ $step['label'] }}</p>
                                @if($step['date'])
                                    <p class="text-[9px] font-black {{ $step['done'] ? 'text-slate-900' : 'text-slate-400' }}">{{ $step['date']->format('d/m/Y') }}</p>
                                @endif
                            </div>
                            @if($i < count($steps) - 1)
                                <div class="w-6 h-0.5 {{ $step['done'] ? "bg-{$step['color']}-400" : 'bg-slate-200' }} mr-3"></div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Statut actuel --}}
                <div class="mt-6 flex items-center gap-3">
                    <span class="text-[8px] font-black text-slate-400 uppercase">{{ __("Statut actuel :") }}</span>
                    <span @class(['text-[10px] font-black uppercase px-4 py-2 rounded-full',
                        'bg-slate-100 text-slate-500' => $plan->status === 'planifie',
                        'bg-blue-100 text-blue-600' => $plan->status === 'commande',
                        'bg-emerald-100 text-emerald-600' => $plan->status === 'en_cours',
                        'bg-slate-800 text-white' => $plan->status === 'termine',
                        'bg-red-100 text-red-500' => $plan->status === 'annule'])>
                        @if($plan->status === 'planifie') 📅 {{ __("Planifié") }}
                        @elseif($plan->status === 'commande') 📞 {{ __("Poussins Commandés") }}
                        @elseif($plan->status === 'en_cours') 🐣 {{ __("Bande Active") }}
                        @elseif($plan->status === 'termine') ✅ {{ __("Terminé") }}
                        @elseif($plan->status === 'annule') ❌ {{ __("Annulé") }}
                        @endif
                    </span>
                </div>
            </div>

            {{-- ═══ INFORMATIONS ═══ --}}
            <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm mb-6">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-4">{{ __("Informations") }}</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <div class="p-4 bg-slate-50 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase mb-1">{{ __("Bâtiment") }}</p>
                        <p class="text-sm font-black text-slate-900">{{ $plan->building->name ?? '—' }}</p>
                    </div>
                    <div class="p-4 bg-slate-50 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase mb-1">{{ __("Type") }}</p>
                        <p class="text-sm font-black text-slate-900 uppercase">{{ $plan->batch_type }}</p>
                    </div>
                    <div class="p-4 bg-slate-50 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase mb-1">{{ __("Souche") }}</p>
                        <p class="text-sm font-black text-slate-900">{{ $plan->model_name ?? '—' }}</p>
                    </div>
                    <div class="p-4 bg-slate-50 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase mb-1">{{ __("Quantité prévue") }}</p>
                        <p class="text-sm font-black text-emerald-600">{{ number_format($plan->planned_quantity) }}</p>
                    </div>
                    <div class="p-4 bg-slate-50 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase mb-1">{{ __("Fournisseur") }}</p>
                        <p class="text-sm font-black text-slate-900">{{ $plan->provider->name ?? '—' }}</p>
                    </div>
                    <div class="p-4 bg-slate-50 rounded-2xl">
                        <p class="text-[8px] font-black text-slate-400 uppercase mb-1">{{ __("Créé par") }}</p>
                        <p class="text-sm font-black text-slate-900">{{ $plan->creator->name ?? '—' }}</p>
                    </div>
                </div>
            </div>

            {{-- ═══ ACTIONS WORKFLOW (transitions valides uniquement) ═══ --}}
            @can('planning.M')
            <div class="mb-6">

                {{-- ── PLANIFIÉ : peut Commander ou Annuler ── --}}
                @if($plan->status === 'planifie')
                <div class="bg-blue-50 p-8 rounded-[2.5rem] border border-blue-200">
                    <h3 class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-forward-step"></i> {{ __("Prochaine étape") }}
                    </h3>
                    <p class="text-[9px] text-blue-700 mb-6 normal-case">
                        {{ __("Les poussins doivent être commandés au moins 8 semaines avant l'arrivée prévue.") }}
                        @if($plan->is_overdue)
                            <span class="text-red-600 font-black"> ⚠ {{ __("La date de commande est dépassée de") }} {{ $plan->chick_order_deadline->diffInDays(now()) }} {{ __("jours !") }}</span>
                        @else
                            {{ __("Date limite :") }} <span class="font-black">{{ $plan->chick_order_deadline?->format('d/m/Y') ?? '—' }}</span>.
                        @endif
                    </p>
                    <div class="flex gap-3">
                        <form method="POST" action="{{ route('planning.status', $plan) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="commande">
                            <button type="submit" class="bg-blue-500 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all border-none cursor-pointer shadow-lg">
                                <i class="fa-solid fa-phone mr-2"></i> {{ __("Marquer comme Commandé") }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('planning.status', $plan) }}" onsubmit="return confirm('{{ __("Annuler cette planification ?") }}')">
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="annule">
                            <button type="submit" class="bg-white text-red-500 px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-50 transition-all border border-red-200 cursor-pointer">
                                <i class="fa-solid fa-xmark mr-1"></i> {{ __("Annuler") }}
                            </button>
                        </form>
                    </div>
                </div>
                @endif

                {{-- ── COMMANDÉ : peut Activer (créer le lot) ou Annuler ── --}}
                @if($plan->status === 'commande')
                <div class="bg-emerald-50 p-8 rounded-[2.5rem] border border-emerald-200">
                    <h3 class="text-[10px] font-black text-emerald-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-rocket"></i> {{ __("Poussins commandés — Prêt à activer") }}
                    </h3>
                    <p class="text-[9px] text-emerald-700 mb-6 normal-case">
                        {{ __("Quand les poussins arrivent, cliquez sur \"Activer la bande\" pour saisir les données réelles (quantité reçue, mortalité transport, prix) et créer automatiquement le lot dans le système.") }}
                    </p>
                    <div class="flex gap-3">
                        @can('planning.M')
                            <a href="{{ route('planning.activate', $plan) }}" class="bg-emerald-500 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-lg no-underline flex items-center gap-2">
                                <i class="fa-solid fa-rocket"></i> {{ __("Activer la bande & Créer le lot") }}
                            </a>
                        @endcan
                        <form method="POST" action="{{ route('planning.status', $plan) }}" onsubmit="return confirm('{{ __("Annuler cette commande ?") }}')">
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="annule">
                            <button type="submit" class="bg-white text-red-500 px-6 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-50 transition-all border border-red-200 cursor-pointer">
                                <i class="fa-solid fa-xmark mr-1"></i> {{ __("Annuler") }}
                            </button>
                        </form>
                    </div>
                </div>
                @endif

                {{-- ── EN COURS : peut Terminer, lien vers le lot ── --}}
                @if($plan->status === 'en_cours')
                <div class="bg-amber-50 p-8 rounded-[2.5rem] border border-amber-200">
                    <h3 class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-spinner fa-spin-pulse"></i> {{ __("Bande en cours") }}
                    </h3>
                    <p class="text-[9px] text-amber-700 mb-4 normal-case">
                        {{ __("La bande est active. Fin prévue le") }} <span class="font-black">{{ $plan->planned_end_date->format('d/m/Y') }}</span>
                        ({{ __("dans") }} {{ max(0, now()->diffInDays($plan->planned_end_date, false)) }} {{ __("jours") }}).
                    </p>

                    @if($plan->actual_batch_id)
                    <a href="{{ route('batches.show', $plan->actual_batch_id) }}" class="bg-emerald-500 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-lg no-underline flex items-center gap-2 mb-4 inline-flex">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> {{ __("Voir le lot") }} {{ $plan->actualBatch->code ?? '' }}
                    </a>
                    @endif

                    <form method="POST" action="{{ route('planning.status', $plan) }}" onsubmit="return confirm('{{ __("Confirmer la fin de cette bande ? Le lot sera clôturé.") }}')">
                        @csrf @method('PUT')
                        <input type="hidden" name="status" value="termine">
                        <button type="submit" class="bg-slate-900 text-white px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-800 transition-all border-none cursor-pointer shadow-lg">
                            <i class="fa-solid fa-flag-checkered mr-2"></i> {{ __("Terminer la bande") }}
                        </button>
                    </form>
                </div>
                @endif

                {{-- ── TERMINÉ : pas d'actions, lecture seule ── --}}
                @if($plan->status === 'termine')
                <div class="bg-slate-100 p-8 rounded-[2.5rem] border border-slate-200">
                    <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-check-double"></i> {{ __("Bande terminée") }}
                    </h3>
                    <p class="text-[9px] text-slate-500 normal-case">{{ __("Cette bande est clôturée. Le bâtiment sera disponible après le vide sanitaire") }} ({{ $plan->sanitary_void_end?->format('d/m/Y') ?? '—' }}).</p>
                    @if($plan->actual_batch_id)
                    <a href="{{ route('batches.show', $plan->actual_batch_id) }}" class="mt-4 bg-white text-slate-600 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest no-underline inline-flex items-center gap-2 border border-slate-200 hover:bg-slate-50 transition-all">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> {{ __("Consulter le lot clôturé") }}
                    </a>
                    @endif
                </div>
                @endif

                {{-- ── ANNULÉ : pas d'actions ── --}}
                @if($plan->status === 'annule')
                <div class="bg-red-50 p-8 rounded-[2.5rem] border border-red-200">
                    <h3 class="text-[10px] font-black text-red-500 uppercase tracking-widest mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-ban"></i> {{ __("Planification annulée") }}
                    </h3>
                    <p class="text-[9px] text-red-400 normal-case">{{ __("Cette planification a été annulée. Le bâtiment est libéré pour une nouvelle planification.") }}</p>
                </div>
                @endif

            </div>
            @endcan

            {{-- ═══ NOTES ═══ --}}
            @if($plan->notes)
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ __("Notes") }}</p>
                    <p class="text-xs text-slate-600 normal-case">{{ $plan->notes }}</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
