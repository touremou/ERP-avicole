<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    🧑‍🌾 {{ __("Pointage de présence") }}
                </h2>
                <p class="text-[10px] font-black text-violet-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ \Carbon\Carbon::parse($date)->translatedFormat('l j F Y') }}
                </p>
            </div>
            <a href="{{ route('attendance.report') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-2xl text-[9px] font-black uppercase tracking-widest hover:bg-violet-50 hover:text-violet-600 transition-all no-underline shadow-sm italic">
                <i class="fa-solid fa-chart-column"></i> {{ __("Rapport de présence") }}
            </a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- Sélecteur de date (GET) --}}
            <form method="GET" action="{{ route('attendance.index') }}" class="mb-6 flex items-center gap-3 bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
                <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest">{{ __("Date") }}</label>
                <input type="date" name="date" value="{{ $date }}" max="{{ date('Y-m-d') }}" class="bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                <button type="submit" class="px-5 py-3 bg-slate-900 text-white rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-violet-600 transition-all border-none cursor-pointer">{{ __("Charger") }}</button>
            </form>

            @can('annuaire.C')
            <form method="POST" action="{{ route('attendance.store') }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">

                <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden divide-y divide-slate-50">
                    @forelse($rows as $row)
                        @php $emp = $row['employee']; @endphp
                        <div class="flex items-center justify-between gap-4 p-5">
                            <div class="min-w-0">
                                <p class="text-xs font-black text-slate-800 uppercase truncate">{{ $emp->first_name }} {{ $emp->last_name }}</p>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ $emp->job_title ?? '—' }}
                                    @if($row['locked'])<span class="text-amber-500 ml-1">· {{ __("congé validé") }}</span>@endif
                                </p>
                            </div>
                            <select name="status[{{ $emp->id }}]" @class([
                                'shrink-0 w-36 p-3 rounded-xl font-black text-[10px] uppercase shadow-inner outline-none appearance-none cursor-pointer border-none',
                                'bg-emerald-50 text-emerald-700' => $row['status'] === 'present',
                                'bg-amber-50 text-amber-700' => $row['status'] === 'retard',
                                'bg-red-50 text-red-700' => $row['status'] === 'absent',
                                'bg-violet-50 text-violet-700' => $row['status'] === 'conge',
                            ])>
                                @foreach($statuses as $value => $label)
                                    <option value="{{ $value }}" {{ $row['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @empty
                        <p class="p-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">{{ __("Aucun employé actif.") }}</p>
                    @endforelse
                </div>

                @if($rows->isNotEmpty())
                <button type="submit" class="mt-6 w-full bg-slate-900 text-white font-black py-5 rounded-[2rem] hover:bg-violet-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl border-none cursor-pointer">
                    <i class="fa-solid fa-check mr-2"></i> {{ $saved ? __("Mettre à jour le pointage") : __("Enregistrer le pointage") }}
                </button>
                @endif
            </form>
            @else
                <p class="p-8 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest bg-white rounded-[2rem] border border-slate-100">{{ __("Lecture seule — permission de création requise pour pointer.") }}</p>
            @endcan
        </div>
    </div>
</x-app-layout>
