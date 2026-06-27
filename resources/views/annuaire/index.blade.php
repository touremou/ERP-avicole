<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">👥 {{ __("Annuaire / RH") }}</h2>
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mt-1 italic leading-none">{{ __("Équipe · Présence · Partenaires") }}</p>
            </div>
            @can('annuaire.C')
            <a href="{{ route('employees.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-600 transition-all no-underline shadow-lg italic"><i class="fa-solid fa-user-plus"></i> {{ __("Nouvel employé") }}</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Effectif actif") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['headcount'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Présents aujourd'hui") }}</p>
                    <p class="text-2xl font-black text-emerald-600 leading-none">{{ $kpis['present'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Masse salariale") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ number_format($kpis['payroll'], 0, ',', ' ') }}</p>
                    <p class="text-[8px] text-slate-300 font-black uppercase mt-1">{{ currency() }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">{{ __("Fournisseurs") }}</p>
                    <p class="text-2xl font-black text-slate-800 leading-none">{{ $kpis['providers'] }}</p>
                </div>
            </div>

            @php
                $groups = [
                    ['title' => 'Équipe', 'color' => 'blue', 'items' => [
                        ['label' => 'Employés', 'icon' => 'fa-id-badge', 'route' => 'employees.index', 'can' => 'annuaire.L'],
                        ['label' => 'Présence', 'icon' => 'fa-user-check', 'route' => 'attendance.index', 'can' => 'annuaire.L'],
                        ['label' => 'Tâches', 'icon' => 'fa-list-check', 'route' => 'tasks.index', 'can' => 'annuaire.L'],
                    ]],
                    ['title' => 'Paie', 'color' => 'emerald', 'items' => [
                        ['label' => 'Paie', 'icon' => 'fa-money-check-dollar', 'route' => 'payroll.index', 'can' => 'annuaire.L'],
                        ['label' => 'Congés', 'icon' => 'fa-umbrella-beach', 'route' => 'payroll.leaves', 'can' => 'annuaire.L'],
                    ]],
                    ['title' => 'Partenaires', 'color' => 'orange', 'items' => [
                        ['label' => 'Fournisseurs', 'icon' => 'fa-truck-field', 'route' => 'providers.index', 'can' => 'annuaire.L'],
                    ]],
                ];
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach($groups as $g)
                <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-{{ $g['color'] }}-500 mb-4">{{ __($g['title']) }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach($g['items'] as $it)
                            @can($it['can'])
                            @if(\Illuminate\Support\Facades\Route::has($it['route']))
                            <a href="{{ route($it['route']) }}" class="flex flex-col items-center justify-center gap-2 p-4 bg-slate-50 rounded-2xl hover:bg-{{ $g['color'] }}-50 hover:text-{{ $g['color'] }}-600 transition-all no-underline text-slate-600 text-center">
                                <i class="fa-solid {{ $it['icon'] }} text-lg"></i>
                                <span class="text-[8px] font-black uppercase tracking-widest leading-tight">{{ __($it['label']) }}</span>
                            </a>
                            @endif
                            @endcan
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm p-6">
                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-4">{{ __("Présence du jour") }}</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach($presence as $label => $count)
                    <div class="bg-slate-50 rounded-2xl p-4 text-center">
                        <p class="text-xl font-black text-slate-800 leading-none">{{ $count }}</p>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-1">{{ $label }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
