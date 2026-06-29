<x-app-layout>
    <x-slot name="header">
        {{-- Feuille (route à paramètre) → x-back, pas de double bouton. --}}
        <div class="flex items-center gap-4 text-left">
            <x-back :to="route('health.incidents.index')" />
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    🩺 {{ __("Incident sanitaire") }} #{{ $incident->id }}
                </h2>
                <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest mt-1 italic leading-none">
                    @if($incident->batch){{ __("Lot") }} #{{ $incident->batch->code }} • @endif{{ $incident->building->name ?? __("Bâtiment inconnu") }}
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-10 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-flash />

            {{-- Bandeau statut + gravité + quarantaine --}}
            <div class="flex flex-wrap items-center gap-3">
                <span @class([
                    'px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest',
                    'bg-rose-600 text-white' => $incident->status === 'en_attente',
                    'bg-amber-100 text-amber-700' => $incident->status === 'diagnostique',
                    'bg-emerald-100 text-emerald-700' => $incident->status === 'resolu',
                ])>{{ str_replace('_', ' ', $incident->status) }}</span>
                <span class="px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-{{ $incident->severity_color }}-100 text-{{ $incident->severity_color }}-700">
                    {{ __("Gravité") }} : {{ $incident->severity_label }}
                </span>
                @if($incident->is_quarantined)
                    <span class="px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-purple-100 text-purple-700"><i class="fa-solid fa-shield-virus mr-1"></i>{{ __("En quarantaine") }}</span>
                @endif
                @if($incident->is_overdue)
                    <span class="px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-amber-500 text-white animate-pulse">{{ __("Diagnostic en retard") }}</span>
                @endif
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- Faits + photo --}}
                <div class="lg:col-span-2 space-y-6">
                    @if($incident->photo_path)
                        <img src="{{ media_url($incident->photo_path) }}" alt="Autopsie" class="w-full max-h-80 object-contain bg-slate-900 rounded-[2rem]">
                    @endif

                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[9px] uppercase tracking-widest text-slate-400 font-black mb-2">{{ __("Symptômes observés") }}</p>
                        <p class="text-sm text-slate-700 leading-relaxed">{{ $incident->symptoms }}</p>
                    </div>

                    @if($incident->suspected_disease)
                        <div class="bg-blue-50 p-6 rounded-[2rem] border border-blue-100">
                            <p class="text-[9px] uppercase tracking-widest text-blue-400 font-black mb-1"><i class="fa-solid fa-user-doctor mr-1"></i>{{ __("Diagnostic") }}</p>
                            <p class="text-sm text-blue-900 font-black uppercase">{{ $incident->suspected_disease }}</p>
                            @if($incident->vet_prescription)
                                <p class="text-xs text-blue-700 mt-2 leading-relaxed">{{ $incident->vet_prescription }}</p>
                            @endif
                        </div>
                    @endif

                    @if($incident->resolution_notes)
                        <div class="bg-emerald-50 p-6 rounded-[2rem] border border-emerald-100">
                            <p class="text-[9px] uppercase tracking-widest text-emerald-500 font-black mb-1"><i class="fa-solid fa-check-double mr-1"></i>{{ __("Résolution") }}</p>
                            <p class="text-xs text-emerald-800 leading-relaxed">{{ $incident->resolution_notes }}</p>
                        </div>
                    @endif
                </div>

                {{-- Chronologie + chiffres --}}
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm space-y-3 text-[11px]">
                        <div class="flex justify-between"><span class="text-slate-400 uppercase text-[9px] font-black">{{ __("Cadavres signalés") }}</span><span class="font-black text-rose-600">{{ $incident->mortality_count }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-400 uppercase text-[9px] font-black">{{ __("Coût traitement") }}</span><span class="font-black text-slate-800">{{ number_format((float) $incident->treatment_cost, 0, ',', ' ') }} {{ currency() }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-400 uppercase text-[9px] font-black">{{ __("Jours ouverts") }}</span><span class="font-black text-slate-800">{{ $incident->days_open }} j</span></div>
                        @if($incident->daily_check_id)
                            <div class="flex justify-between"><span class="text-slate-400 uppercase text-[9px] font-black">{{ __("Pointage d'origine") }}</span><span class="font-black text-blue-600">{{ optional($incident->dailyCheck)->check_date?->format('d/m/Y') }}</span></div>
                        @endif
                    </div>

                    {{-- Timeline --}}
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <p class="text-[9px] uppercase tracking-widest text-slate-400 font-black mb-4">{{ __("Chronologie") }}</p>
                        <ol class="relative border-s-2 border-slate-100 ms-2 space-y-5">
                            <li class="ms-4">
                                <span class="absolute w-3 h-3 bg-rose-500 rounded-full -start-[7px] mt-1"></span>
                                <p class="text-[10px] font-black text-slate-700 uppercase">{{ __("Déclaré") }}</p>
                                <p class="text-[9px] text-slate-400">{{ $incident->incident_date?->format('d/m/Y') }} · {{ $incident->user->name ?? __("agent") }}</p>
                            </li>
                            @if($incident->diagnosed_at)
                                <li class="ms-4">
                                    <span class="absolute w-3 h-3 bg-blue-500 rounded-full -start-[7px] mt-1"></span>
                                    <p class="text-[10px] font-black text-slate-700 uppercase">{{ __("Diagnostiqué") }}</p>
                                    <p class="text-[9px] text-slate-400">{{ $incident->diagnosed_at->format('d/m/Y H:i') }} · {{ $incident->diagnosedBy->name ?? '—' }}</p>
                                </li>
                            @endif
                            @if($incident->quarantine_started_at)
                                <li class="ms-4">
                                    <span class="absolute w-3 h-3 bg-purple-500 rounded-full -start-[7px] mt-1"></span>
                                    <p class="text-[10px] font-black text-slate-700 uppercase">{{ __("Quarantaine") }}</p>
                                    <p class="text-[9px] text-slate-400">{{ $incident->quarantine_started_at->format('d/m/Y') }}@if($incident->quarantine_ended_at) → {{ $incident->quarantine_ended_at->format('d/m/Y') }}@else ({{ __("en cours") }})@endif</p>
                                </li>
                            @endif
                            @if($incident->resolved_at)
                                <li class="ms-4">
                                    <span class="absolute w-3 h-3 bg-emerald-500 rounded-full -start-[7px] mt-1"></span>
                                    <p class="text-[10px] font-black text-slate-700 uppercase">{{ __("Résolu") }}</p>
                                    <p class="text-[9px] text-slate-400">{{ $incident->resolved_at->format('d/m/Y H:i') }} · {{ $incident->resolvedBy->name ?? '—' }}</p>
                                </li>
                            @endif
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
