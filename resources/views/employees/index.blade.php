<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Personnel')" :subtitle="__('Gestion des collaborateurs & Effectifs')" icon="fa-id-card" accent="blue">
            <x-slot name="actions">
                {{-- MODULE ANNUAIRE : Recrutement (C) --}}
                @can('rh.C')
                <a href="{{ route('employees.create') }}" class="group bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl shadow-slate-900/10 italic no-underline">
                    <i class="fas fa-user-plus mr-2 group-hover:rotate-12 transition-transform"></i> {{ __("Recruter") }}
                </a>
                @endcan

                {{-- MODULE RH : Accès à la Paie (L) --}}
                @can('rh.L')
                <a href="{{ route('payroll.index') }}" class="bg-blue-600 text-white px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-blue-700 transition-all shadow-lg italic no-underline">
                    <i class="fas fa-money-bill-wave mr-2"></i> {{ __("Paie") }}
                </a>
                @endcan

                {{-- MODULE RH : Accès aux Congés (L) --}}
                @can('rh.L')
                <a href="{{ route('payroll.leaves') }}" class="bg-white border border-slate-200 text-slate-600 px-6 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-amber-50 transition-all shadow-sm italic no-underline">
                    <i class="fas fa-calendar-xmark mr-2 text-amber-500"></i> {{ __("Congés") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- MINI DASHBOARD RH (L) --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10 uppercase text-left">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden">
                    <p class="text-[8px] text-slate-400 tracking-widest mb-2 font-black italic">{{ __("Total Effectif") }}</p>
                    <p class="text-3xl font-black text-slate-800 tracking-tighter leading-none">{{ $employees->count() }}</p>
                    <div class="absolute -right-2 -bottom-2 opacity-5 text-4xl italic font-black">ALL</div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden">
                    <p class="text-[8px] text-emerald-500 tracking-widest mb-2 font-black italic">{{ __("Présents") }}</p>
                    <p class="text-3xl font-black text-emerald-600 tracking-tighter leading-none">{{ $employees->where('status', 'Actif')->count() }}</p>
                    <div class="absolute -right-2 -bottom-2 opacity-5 text-emerald-500 text-4xl italic font-black">OK</div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden">
                    <p class="text-[8px] text-blue-500 tracking-widest mb-2 font-black italic">{{ __("Masse Salariale") }}</p>
                    <p class="text-2xl font-black text-blue-600 tracking-tighter leading-none">{{ number_format($employees->sum('salary'), 0, ',', ' ') }} <span class="text-[10px]">{{ setting('general.currency', 'GNF') }}</span></p>
                    <div class="absolute -right-2 -bottom-2 opacity-5 text-blue-500 text-4xl italic font-black">{{ setting('general.currency', 'GNF') }}</div>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden">
                    <p class="text-[8px] text-orange-500 tracking-widest mb-2 font-black italic">{{ __("En Congé") }}</p>
                    <p class="text-3xl font-black text-orange-600 tracking-tighter leading-none">{{ $employees->where('status', 'Congé')->count() }}</p>
                    <div class="absolute -right-2 -bottom-2 opacity-5 text-orange-500 text-4xl italic font-black">OFF</div>
                </div>
            </div>

            {{-- RÉSULTATS DE LIAISON EN MASSE (identifiants créés — affichés une seule fois) --}}
            @if(session('bulk_access_results') && count(session('bulk_access_results')) > 0)
            <div class="mb-6 bg-white rounded-[2rem] border-2 border-biocrest/40 shadow-sm p-6 text-left">
                <p class="text-[10px] font-black uppercase tracking-widest text-biocrest mb-1"><i class="fa-solid fa-key mr-2"></i>{{ __("Accès créés — notez ces identifiants (affichés une seule fois)") }}</p>
                <p class="text-[9px] font-black text-slate-400 uppercase mb-4 italic">{{ __("Communiquez à chaque agent son identifiant et son mot de passe temporaire.") }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead><tr class="text-[8px] font-black uppercase tracking-widest text-slate-400"><th class="py-2 pr-4">{{ __("Agent") }}</th><th class="py-2 pr-4">{{ __("Identifiant") }}</th><th class="py-2">{{ __("Mot de passe") }}</th></tr></thead>
                        <tbody class="font-mono">
                            @foreach(session('bulk_access_results') as $r)
                            <tr class="border-t border-slate-50"><td class="py-2 pr-4 font-black not-italic uppercase">{{ $r['name'] }}</td><td class="py-2 pr-4 select-all">{{ $r['login'] }}</td><td class="py-2 font-black select-all text-biocrest">{{ $r['password'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(session('bulk_access_linked') && count(session('bulk_access_linked')) > 0)
                <p class="mt-4 text-[9px] font-black text-slate-500 uppercase italic">{{ __("Comptes existants rattachés :") }} {{ implode(', ', session('bulk_access_linked')) }}</p>
                @endif
            </div>
            @endif

            {{-- TABLEAU DES EMPLOYÉS (L) --}}
            <form method="POST" action="{{ route('employees.access.bulk') }}"
                  x-data="{ selected: [], allIds: {{ $employees->whereNull('user_id')->pluck('id')->map(fn($id) => (string) $id)->values()->toJson() }} }">
                @csrf

                {{-- BARRE DE LIAISON EN MASSE (admin.S) --}}
                @can('admin.S')
                <div x-show="selected.length > 0" x-cloak
                     class="mb-4 flex flex-wrap items-center gap-3 bg-white rounded-2xl border-2 border-biocrest/40 shadow-sm p-4 italic">
                    <span class="text-[10px] font-black uppercase text-slate-600 tracking-widest"><span x-text="selected.length"></span> {{ __("agent(s) sans accès sélectionné(s)") }}</span>
                    <select name="role_id" required class="p-3 bg-slate-50 rounded-xl font-bold border-none shadow-inner text-slate-700 text-xs italic">
                        <option value="" disabled selected>{{ __("— Rôle du compte —") }}</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->display_name ?? $role->label ?? $role->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="bg-biocrest text-white px-6 py-3 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-biocrest-600 transition-all shadow-md flex items-center">
                        <i class="fa-solid fa-user-check mr-2"></i>{{ __("Créer / lier les accès") }}
                    </button>
                    <button type="button" @click="selected = []" class="text-slate-400 hover:text-slate-600 text-[10px] font-black uppercase tracking-widest">{{ __("Annuler") }}</button>
                </div>
                @endcan

            <div class="bg-white rounded-[3.5rem] shadow-sm border border-slate-100 overflow-hidden text-left">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-100 uppercase italic">
                            @can('admin.S')
                            <th class="pl-8 py-6 w-8">
                                <input type="checkbox" @change="selected = $event.target.checked ? [...allIds] : []" :checked="allIds.length > 0 && selected.length === allIds.length" class="w-4 h-4 rounded accent-biocrest cursor-pointer" title="{{ __('Tout sélectionner (sans accès)') }}">
                            </th>
                            @endcan
                            <th class="px-10 py-6 text-[9px] font-black text-slate-400 tracking-widest">Collaborateur</th>
                            <th class="px-6 py-6 text-[9px] font-black text-slate-400 tracking-widest">Poste & Département</th>
                            <th class="px-6 py-6 text-[9px] font-black text-slate-400 tracking-widest text-center">Statut</th>
                            <th class="px-6 py-6 text-[9px] font-black text-slate-400 tracking-widest">Salaire Base</th>
                            <th class="px-10 py-6 text-[9px] font-black text-slate-400 tracking-widest text-right">Gestion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 font-bold italic">
                        @forelse($employees as $emp)
                        <tr class="group/row hover:bg-slate-50 transition-all">
                            @can('admin.S')
                            <td class="pl-8 py-7">
                                @unless($emp->user_id)
                                <input type="checkbox" name="employee_ids[]" value="{{ $emp->id }}" x-model="selected" class="w-4 h-4 rounded accent-biocrest cursor-pointer">
                                @else
                                <i class="fa-solid fa-lock text-slate-200 text-xs" title="{{ __('Déjà un accès') }}"></i>
                                @endunless
                            </td>
                            @endcan
                            <td class="px-10 py-7">
                                <div class="flex items-center space-x-5">
                                    <div class="relative">
                                        @if($emp->photo_path)
                                            <img src="{{ media_url($emp->photo_path) }}"
                                                 class="w-14 h-14 rounded-2xl object-cover shadow-sm border-2 border-white ring-1 ring-slate-100"
                                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                            <div class="w-14 h-14 bg-slate-900 text-blue-400 rounded-2xl items-center justify-center font-black text-xl border-2 border-white shadow-sm ring-1 ring-slate-100 uppercase italic hidden">
                                                {{ substr($emp->first_name, 0, 1) }}{{ substr($emp->last_name, 0, 1) }}
                                            </div>
                                        @else
                                            <div class="w-14 h-14 bg-slate-900 text-blue-400 rounded-2xl flex items-center justify-center font-black text-xl border-2 border-white shadow-sm ring-1 ring-slate-100 uppercase italic">
                                                {{ substr($emp->first_name, 0, 1) }}{{ substr($emp->last_name, 0, 1) }}
                                            </div>
                                        @endif
                                        <div @class(['absolute -bottom-1 -right-1 w-4 h-4 border-2 border-white rounded-full shadow-sm', 'bg-emerald-500' => $emp->status === 'Actif', 'bg-blue-500' => $emp->status === 'Congé', 'bg-slate-300' => $emp->status === 'Suspendu'])></div>
                                    </div>
                                    <div>
                                        <p class="font-black text-slate-800 text-base leading-tight tracking-tighter uppercase">{{ $emp->first_name }} {{ $emp->last_name }}</p>
                                        <p class="text-[9px] font-mono font-black text-slate-400 uppercase tracking-tighter mt-1">{{ $emp->employee_id }}</p>
                                        @if($emp->user_id)
                                        <span class="inline-block mt-1 text-[7px] font-black px-2 py-0.5 rounded bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase italic"><i class="fa-solid fa-circle-check mr-0.5"></i>{{ __("Compte lié") }}</span>
                                        @else
                                        <span class="inline-block mt-1 text-[7px] font-black px-2 py-0.5 rounded bg-amber-50 text-amber-600 border border-amber-100 uppercase italic">{{ __("Sans accès") }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-7">
                                <p class="text-xs font-black text-slate-700 uppercase leading-none mb-1">{{ $emp->job_title }}</p>
                                <p class="text-[9px] font-black text-blue-500 uppercase tracking-widest italic">{{ $emp->department ?? 'Général' }}</p>
                            </td>
                            <td class="px-6 py-7 text-center">
                                @php
                                    $statusColor = match($emp->status) {
                                        'Actif' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
                                        'Congé' => 'bg-blue-50 text-blue-600 border-blue-100',
                                        'Suspendu' => 'bg-orange-50 text-orange-600 border-orange-100',
                                        default => 'bg-slate-100 text-slate-500 border-slate-200'
                                    };
                                @endphp
                                <span class="px-4 py-2 rounded-xl text-[8px] font-black uppercase tracking-widest border italic shadow-sm {{ $statusColor }}">
                                    {{ $emp->status ?? 'Actif' }}
                                </span>
                            </td>
                            <td class="px-6 py-7">
                                <div class="flex flex-col leading-none">
                                    <span class="font-black text-slate-800 text-sm italic tracking-tighter">{{ number_format($emp->salary, 0, ',', ' ') }}</span>
                                    <span class="text-[8px] font-black text-slate-400 uppercase tracking-tighter italic mt-1">{{ setting('general.currency', 'GNF') }} / Mensuel</span>
                                </div>
                            </td>
                            <td class="px-10 py-7 text-right">
                                <div class="flex justify-end items-center gap-3">
                                    <a href="{{ route('employees.show', $emp->id) }}" 
                                       class="flex items-center gap-2 px-6 py-3 bg-slate-50 text-slate-900 rounded-xl text-[9px] font-black uppercase italic tracking-widest hover:bg-slate-900 hover:text-white transition-all shadow-inner border border-slate-100 no-underline">
                                        <i class="fas fa-eye"></i>
                                        <span>Fiche Agent</span>
                                    </a>
                                    
                                    {{-- Permission M : Édition --}}
                                    @can('rh.M')
                                    <a href="{{ route('employees.edit', $emp->id) }}" class="w-11 h-11 flex items-center justify-center text-slate-300 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all border border-transparent hover:border-blue-100">
                                        <i class="fas fa-pen-nib text-xs"></i>
                                    </a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="{{ auth()->user()?->can('admin.S') ? 6 : 5 }}" class="px-8 py-32 text-center">
                                <div class="w-20 h-20 bg-slate-50 rounded-[2.5rem] flex items-center justify-center mx-auto mb-6 border border-slate-100 opacity-50">
                                    <i class="fas fa-users-slash text-2xl text-slate-200"></i>
                                </div>
                                <p class="text-slate-400 font-black uppercase tracking-[0.3em] text-[10px] italic">Registre RH Vierge</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </form>

            {{-- ZONE DE MAINTENANCE (S) --}}
            @can('rh.S')
            <div class="mt-24 py-12 border-t border-slate-100 flex justify-center">
                <a href="{{ route('trash.index') }}" class="group flex items-center gap-6 bg-slate-50 px-8 py-4 rounded-[2.5rem] hover:bg-slate-900 transition-all duration-700 border border-dashed border-slate-200 hover:border-slate-800 no-underline text-left">
                    <div class="flex flex-col items-start leading-none italic">
                        <span class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1 group-hover:text-blue-500 transition-colors">Maintenance Sécurité (S)</span>
                        <span class="text-[10px] font-black text-slate-400 uppercase italic group-hover:text-white transition-colors">Profils Archivés & Récupération</span>
                    </div>
                    <div class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center text-slate-300 group-hover:bg-blue-600 group-hover:text-white transition-all shadow-sm rotate-3 group-hover:rotate-0">
                        <i class="fas fa-archive text-sm"></i>
                    </div>
                </a>
            </div>
            @endcan

        </div>
    </div>
</x-app-layout>