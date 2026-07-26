@php
    /**
     * Suivi des contrats à terme — l'écran qui force la décision.
     *
     * Rangé par URGENCE DE DÉCISION, pas par date : un terme DÉPASSÉ sans acte
     * passe devant une échéance dans trois semaines, parce que c'est lui qui
     * expose (un CDD qui court au-delà de son terme se requalifie).
     */
    $stageStyle = [
        'expire'    => ['bg-rose-600', __('Terme dépassé')],
        'a_decider' => ['bg-amber-500', __('À décider')],
        'en_cours'  => ['bg-slate-400', __('En cours')],
        'preavis'   => ['bg-slate-900', __('Préavis émis')],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Contrats à terme')" :subtitle="__('Prolonger ou notifier la fin — CDD et Journaliers')"
                       icon="fa-file-signature" accent="amber" :back="route('employees.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold space-y-8">

            @if(session('success'))
                <div class="bg-emerald-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-rose-600 text-white p-5 rounded-[2rem] text-[10px] font-black uppercase tracking-widest">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="bg-rose-600 text-white p-6 rounded-[2rem]">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black">
                        @foreach($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            {{-- Fenêtre de surveillance --}}
            <form method="GET" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-[9px] font-black text-slate-400 uppercase ml-2 mb-1 italic">{{ __("Fenêtre de surveillance (jours)") }}</label>
                    <input type="number" name="days" min="1" max="365" value="{{ $days }}"
                           class="bg-slate-50 border-none rounded-2xl p-4 font-black text-slate-800 shadow-inner italic w-40">
                </div>
                <button type="submit" class="bg-slate-100 text-slate-700 px-8 py-4 rounded-2xl font-black uppercase italic tracking-widest text-[10px] hover:bg-slate-200 transition-all">
                    {{ __("Appliquer") }}
                </button>
                <p class="text-[9px] font-bold text-slate-400 uppercase italic ml-auto max-w-md leading-relaxed">
                    {{ __("Valeur par défaut réglable dans les paramètres RH (rh.contract_notice_days).") }}
                </p>
            </form>

            {{-- À DÉCIDER --}}
            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-6">
                    {{ __("Décisions attendues") }}
                    <span class="ml-2 bg-slate-900 text-white px-3 py-1 rounded-full">{{ $toDecide->count() }}</span>
                </h3>

                @if($toDecide->isEmpty())
                    <p class="text-[10px] font-black text-emerald-600 uppercase italic">
                        <i class="fa-solid fa-circle-check mr-2"></i>{{ __("Aucun contrat à terme n'arrive à échéance dans la fenêtre.") }}
                    </p>
                @else
                    <div class="space-y-4">
                        @foreach($toDecide as $employee)
                            @php
                                $left = $employee->days_until_contract_end;
                                [$colour, $label] = $stageStyle[$employee->contract_stage] ?? $stageStyle['en_cours'];
                            @endphp
                            <div class="bg-slate-50 p-6 rounded-[2rem] border {{ $left < 0 ? 'border-rose-200' : 'border-slate-100' }}">
                                <div class="flex flex-wrap gap-4 items-center justify-between mb-4">
                                    <div>
                                        <a href="{{ route('employees.show', $employee->id) }}" class="text-sm font-black text-slate-900 no-underline hover:text-emerald-600">
                                            {{ $employee->name }}
                                        </a>
                                        <p class="text-[9px] font-bold text-slate-400 uppercase italic mt-1">
                                            {{ $employee->employee_id }} · {{ $employee->job_title }} · {{ $employee->contract_type }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <span class="{{ $colour }} text-white px-4 py-2 rounded-full text-[9px] font-black uppercase tracking-widest">{{ $label }}</span>
                                        <p class="text-[10px] font-black text-slate-700 uppercase italic mt-2">
                                            {{ $employee->contract_end_date->format('d/m/Y') }}
                                            —
                                            @if($left < 0)
                                                {{ trans_choice('{1} dépassé de :n jour|[2,*] dépassé de :n jours', abs($left), ['n' => abs($left)]) }}
                                            @else
                                                {{ __('J-:n', ['n' => $left]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                @if($left < 0)
                                    <p class="text-[9px] font-black text-rose-600 uppercase italic mb-4 leading-relaxed">
                                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                        {{ __("Le terme est passé et aucune décision n'est enregistrée. Un contrat à durée déterminée qui continue sans acte se requalifie.") }}
                                    </p>
                                @endif

                                @if($canDecide)
                                    <div class="grid md:grid-cols-2 gap-4">
                                        {{-- PROLONGER --}}
                                        <form method="POST" action="{{ route('employees.contracts.prolong', $employee) }}"
                                              class="bg-white p-5 rounded-[1.5rem] border border-emerald-100 space-y-3">
                                            @csrf
                                            <p class="text-[9px] font-black text-emerald-600 uppercase tracking-widest">{{ __("Prolonger") }}</p>
                                            <input type="date" name="new_end_date" required
                                                   min="{{ now()->addDay()->toDateString() }}"
                                                   class="w-full p-4 bg-slate-50 rounded-xl border-none shadow-inner font-black text-slate-800 italic text-[11px]">
                                            <input type="text" name="reason" maxlength="500" placeholder="{{ __('Motif (facultatif)') }}"
                                                   class="w-full p-4 bg-slate-50 rounded-xl border-none shadow-inner font-bold text-slate-600 italic text-[11px]">
                                            <button type="submit" class="w-full bg-emerald-600 text-white py-4 rounded-xl font-black uppercase italic tracking-widest text-[10px] hover:bg-emerald-700 transition-all">
                                                {{ __("Nouveau terme") }}
                                            </button>
                                        </form>

                                        {{-- PRÉAVIS --}}
                                        <form method="POST" action="{{ route('employees.contracts.notice', $employee) }}"
                                              class="bg-white p-5 rounded-[1.5rem] border border-rose-100 space-y-3"
                                              onsubmit="return confirm('{{ __('Émettre le préavis de fin de contrat ?') }}')">
                                            @csrf
                                            <p class="text-[9px] font-black text-rose-600 uppercase tracking-widest">{{ __("Émettre le préavis") }}</p>
                                            <input type="date" name="last_day"
                                                   value="{{ $employee->contract_end_date->format('Y-m-d') }}"
                                                   max="{{ $employee->contract_end_date->format('Y-m-d') }}"
                                                   class="w-full p-4 bg-slate-50 rounded-xl border-none shadow-inner font-black text-slate-800 italic text-[11px]">
                                            <input type="text" name="reason" maxlength="500" placeholder="{{ __('Motif (facultatif)') }}"
                                                   class="w-full p-4 bg-slate-50 rounded-xl border-none shadow-inner font-bold text-slate-600 italic text-[11px]">
                                            <button type="submit" class="w-full bg-rose-600 text-white py-4 rounded-xl font-black uppercase italic tracking-widest text-[10px] hover:bg-rose-700 transition-all">
                                                {{ __("Notifier la fin") }}
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <p class="text-[9px] font-bold text-slate-400 uppercase italic">
                                        {{ __("Consultation seule — la décision relève d'un gestionnaire RH.") }}
                                    </p>
                                @endif

                                @if($employee->contractEvents->isNotEmpty())
                                    <div class="mt-4 pt-4 border-t border-slate-200">
                                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">{{ __("Historique") }}</p>
                                        @foreach($employee->contractEvents as $event)
                                            <p class="text-[9px] font-bold text-slate-500 italic">
                                                {{ $event->decided_on->format('d/m/Y') }} — {{ $event->label }}
                                                @if($event->new_end_date) → {{ $event->new_end_date->format('d/m/Y') }} @endif
                                                @if($event->reason) · {{ $event->reason }} @endif
                                            </p>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- PRÉAVIS ÉMIS — encore à l'effectif jusqu'au dernier jour --}}
            @if($noticed->isNotEmpty())
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest italic mb-2">
                        {{ __("Préavis émis — sorties à venir") }}
                    </h3>
                    <p class="text-[9px] font-bold text-slate-400 uppercase italic mb-6 leading-relaxed">
                        {{ __("Ces agents restent à l'effectif jusqu'à leur dernier jour : ils sont pointés et payés normalement.") }}
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="border-b border-slate-100">
                                    <th class="pb-2 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Agent") }}</th>
                                    <th class="pb-2 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Préavis du") }}</th>
                                    <th class="pb-2 text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Dernier jour") }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($noticed as $employee)
                                    <tr class="border-b border-slate-50 last:border-0">
                                        <td class="py-3 text-[10px] font-black text-slate-800">
                                            <a href="{{ route('employees.show', $employee->id) }}" class="no-underline text-slate-800 hover:text-emerald-600">{{ $employee->name }}</a>
                                        </td>
                                        <td class="py-3 text-[10px] font-bold text-slate-500">{{ $employee->notice_given_at->format('d/m/Y') }}</td>
                                        <td class="py-3 text-[10px] font-black text-rose-600">{{ optional($employee->contract_end_date)->format('d/m/Y') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
