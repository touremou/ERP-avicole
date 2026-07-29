{{-- AFFECTATIONS AUX SITES — mutation et mise à disposition.

     Le « prêt » n'avait jamais été décidé : il se déduisait du droit d'accès
     donné au COMPTE. Personne ne pouvait donc dire depuis quand, jusqu'à quand,
     ni pourquoi. Cette carte le rend visible et modifiable. --}}
@php
    $today = today();
    $current = $employee->assignments->filter(fn ($a) => $a->coversDate($today));
    $past = $employee->assignments->reject(fn ($a) => $a->coversDate($today));
@endphp

<div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
    <h3 class="text-[9px] font-black uppercase text-slate-400 tracking-widest flex items-center mb-5 italic">
        <span class="w-6 h-[2px] bg-violet-500 mr-2"></span> {{ __("Affectation aux sites") }}
    </h3>

    @if($current->isEmpty())
        <div class="p-4 bg-amber-50 border border-amber-100 rounded-xl text-[10px] font-black text-amber-700 uppercase">
            {{ __("Aucune affectation en cours : cet agent n'apparaît sur aucun site.") }}
        </div>
    @else
        <div class="space-y-2">
            @foreach($current as $assignment)
            <div class="flex items-center gap-3 p-4 bg-slate-50 rounded-xl">
                <span class="text-lg">{{ $assignment->typeEmoji() }}</span>
                <div class="flex-1 min-w-0">
                    <p class="text-[11px] font-black text-slate-800 uppercase">
                        {{ $assignment->farm?->name ?? '—' }}
                        <span class="text-[8px] text-slate-400 ml-1">{{ $assignment->typeLabel() }}</span>
                    </p>
                    <p class="text-[8px] font-bold text-slate-400 mt-0.5">
                        {{ __("Depuis le") }} {{ $assignment->start_date->format('d/m/Y') }}
                        @if($assignment->end_date)
                            — {{ __("jusqu'au") }} {{ $assignment->end_date->format('d/m/Y') }}
                        @endif
                        @if($assignment->reason)
                            <span class="block text-slate-300 italic normal-case">{{ $assignment->reason }}</span>
                        @endif
                    </p>
                </div>
                @can('rh.M')
                @if($assignment->type !== 'mutation')
                <form method="POST" action="{{ route('employees.assignment.end', $assignment) }}"
                      onsubmit="return confirm('{{ __('Mettre fin à cette mise à disposition aujourd\'hui ?') }}')">@csrf
                    <button class="text-[8px] font-black text-red-500 bg-red-50 px-3 py-1.5 rounded-lg border-none cursor-pointer uppercase hover:bg-red-100">{{ __("Clore") }}</button>
                </form>
                @endif
                @endcan
            </div>
            @endforeach
        </div>
    @endif

    @can('rh.M')
    <div class="mt-5 grid md:grid-cols-2 gap-4">
        {{-- MUTATION : le dossier déménage, donc la paie aussi. Réservée à rh.S :
             c'est une décision financière, pas une correction d'affichage. --}}
        @can('rh.S')
        <form method="POST" action="{{ route('employees.transfer', $employee) }}" class="p-4 bg-slate-50 rounded-xl space-y-2">@csrf
            <p class="text-[9px] font-black text-slate-600 uppercase tracking-widest">🔁 {{ __("Muter vers un autre site") }}</p>
            <p class="text-[8px] font-bold text-slate-400 normal-case">{{ __("Le dossier change de site : la paie et l'évaluation suivent.") }}</p>
            <select name="farm_id" required class="w-full text-[10px] p-2 bg-white border-none rounded-lg">
                <option value="">{{ __("— Site d'accueil —") }}</option>
                @foreach($farms as $farm)
                    @if($farm->id !== $employee->farm_id)
                    <option value="{{ $farm->id }}">{{ $farm->name }}</option>
                    @endif
                @endforeach
            </select>
            <input type="date" name="start_date" value="{{ today()->toDateString() }}" required class="w-full text-[10px] p-2 bg-white border-none rounded-lg">
            <input type="text" name="reason" maxlength="255" placeholder="{{ __('Motif (facultatif)') }}" class="w-full text-[10px] p-2 bg-white border-none rounded-lg italic">
            <button class="w-full py-2 bg-slate-900 text-white rounded-lg text-[9px] font-black uppercase tracking-widest border-none cursor-pointer">{{ __("Muter") }}</button>
        </form>
        @endcan

        {{-- MISE À DISPOSITION : le terme est EXIGÉ. Sans terme, un prêt s'oublie
             et devient une mutation de fait que personne n'a décidée — c'est
             exactement ce qui s'était produit avec les accès de compte. --}}
        <form method="POST" action="{{ route('employees.lend', $employee) }}" class="p-4 bg-slate-50 rounded-xl space-y-2">@csrf
            <p class="text-[9px] font-black text-slate-600 uppercase tracking-widest">🤝 {{ __("Mettre à disposition") }}</p>
            <p class="text-[8px] font-bold text-slate-400 normal-case">{{ __("Il travaille sur l'autre site ; son dossier et sa paie ne bougent pas.") }}</p>
            <select name="farm_id" required class="w-full text-[10px] p-2 bg-white border-none rounded-lg">
                <option value="">{{ __("— Site d'accueil —") }}</option>
                @foreach($farms as $farm)
                    @if($farm->id !== $employee->farm_id)
                    <option value="{{ $farm->id }}">{{ $farm->name }}</option>
                    @endif
                @endforeach
            </select>
            <div class="flex gap-2">
                <input type="date" name="start_date" value="{{ today()->toDateString() }}" required class="flex-1 text-[10px] p-2 bg-white border-none rounded-lg">
                <input type="date" name="end_date" required title="{{ __('Terme obligatoire') }}" class="flex-1 text-[10px] p-2 bg-white border-none rounded-lg">
            </div>
            <input type="text" name="reason" maxlength="255" placeholder="{{ __('Motif (facultatif)') }}" class="w-full text-[10px] p-2 bg-white border-none rounded-lg italic">
            <button class="w-full py-2 bg-violet-600 text-white rounded-lg text-[9px] font-black uppercase tracking-widest border-none cursor-pointer">{{ __("Mettre à disposition") }}</button>
        </form>
    </div>
    @endcan

    @if($past->isNotEmpty())
    <details class="mt-5">
        <summary class="text-[8px] font-black text-slate-400 uppercase tracking-widest cursor-pointer">{{ __("Parcours antérieur") }} ({{ $past->count() }})</summary>
        <div class="mt-3 space-y-1">
            @foreach($past as $assignment)
            <p class="text-[9px] font-bold text-slate-400">
                {{ $assignment->typeEmoji() }} {{ $assignment->farm?->name ?? '—' }} —
                {{ $assignment->start_date->format('d/m/Y') }}
                @if($assignment->end_date) → {{ $assignment->end_date->format('d/m/Y') }} @endif
            </p>
            @endforeach
        </div>
    </details>
    @endif
</div>
