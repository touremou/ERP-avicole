<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4 text-left">
                <a href="{{ route('employees.show', $employee->id) }}" class="group text-slate-400 hover:text-slate-800 transition no-underline">
                    <i class="fas fa-arrow-left group-hover:-translate-x-1 transition-transform text-xl"></i>
                </a>
                <div>
                    <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                        {{ __('Édition du profil') }}
                    </h2>
                    <p class="text-[10px] font-black text-blue-500 uppercase tracking-widest mt-2 italic leading-none">
                        {{ $employee->first_name }} {{ $employee->last_name }} • {{ $employee->employee_id }}
                    </p>
                </div>
            </div>
            <div class="hidden md:block">
                <span class="px-4 py-2 bg-blue-50 text-blue-600 rounded-xl text-[10px] font-black uppercase italic tracking-widest border border-blue-100 shadow-sm">
                    <i class="fas fa-pen-nib mr-2"></i> {{ __("Mode Modification (M)") }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-12 italic font-bold">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            {{-- Permission M : Accès à la modification --}}
            @can('annuaire.M')
                @if ($errors->any())
                    <div class="bg-rose-600 text-white p-6 rounded-[2.5rem] mb-8 shadow-xl animate-pulse text-left italic">
                        <h3 class="font-black uppercase text-xs mb-2 italic">⚠️ {{ __("Erreur de validation détectée") }}</h3>
                        <ul class="text-[10px] font-black list-disc ml-5 uppercase tracking-tight">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('employees.update', $employee->id) }}" method="POST" enctype="multipart/form-data" class="space-y-8" id="edit-profile-form">
                    @csrf
                    @method('PUT')

                    {{-- 01. ÉTAT CIVIL --}}
                    <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 relative overflow-hidden text-left italic">
                        <div class="absolute top-0 left-0 w-2 h-full bg-blue-500"></div>
                        <h3 class="text-[10px] font-black text-blue-500 uppercase tracking-widest mb-10 flex items-center italic">
                            <i class="fas fa-id-card-alt mr-3 text-xs"></i> {{ __("01. État Civil & Contact") }}
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Prénom") }}</label>
                                <input type="text" name="first_name" value="{{ old('first_name', $employee->first_name) }}" required class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all shadow-inner text-slate-700 italic">
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Nom de famille") }}</label>
                                <input type="text" name="last_name" value="{{ old('last_name', $employee->last_name) }}" required class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all shadow-inner text-slate-700 uppercase italic">
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Genre") }}</label>
                                <select name="gender" class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-blue-500/10 shadow-inner appearance-none text-slate-700 italic cursor-pointer">
                                    <option value="M" {{ old('gender', $employee->gender) == 'M' ? 'selected' : '' }}>{{ __("Masculin (♂)") }}</option>
                                    <option value="F" {{ old('gender', $employee->gender) == 'F' ? 'selected' : '' }}>{{ __("Féminin (♀)") }}</option>
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("N° de Téléphone") }}</label>
                                <input type="text" name="phone" value="{{ old('phone', $employee->phone) }}" required class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-blue-500/10 shadow-inner text-slate-700 italic">
                            </div>
                        </div>
                    </div>

                    {{-- 02. POSTE & CONTRAT --}}
                    <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 relative overflow-hidden text-left italic">
                        <div class="absolute top-0 left-0 w-2 h-full bg-emerald-500"></div>
                        <h3 class="text-[10px] font-black text-emerald-500 uppercase tracking-widest mb-10 flex items-center italic">
                            <i class="fas fa-briefcase mr-3 text-xs"></i> {{ __("02. Poste & Contrat") }}
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-3 md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Intitulé Exact du Poste") }}</label>
                                <input type="text" name="job_title" value="{{ old('job_title', $employee->job_title) }}" required class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none text-emerald-600 border-none focus:ring-4 focus:ring-emerald-500/10 shadow-inner italic">
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Type de Contrat") }}</label>
                                <select name="contract_type" class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-emerald-500/10 shadow-inner appearance-none text-slate-700 italic cursor-pointer">
                                    <option value="CDI" {{ old('contract_type', $employee->contract_type) == 'CDI' ? 'selected' : '' }}>📄 {{ __("CDI (Indéterminé)") }}</option>
                                    <option value="CDD" {{ old('contract_type', $employee->contract_type) == 'CDD' ? 'selected' : '' }}>⏳ {{ __("CDD (Déterminé)") }}</option>
                                    <option value="Journalier" {{ old('contract_type', $employee->contract_type) == 'Journalier' ? 'selected' : '' }}>☀️ {{ __("Journalier") }}</option>
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Département") }}</label>
                                <select name="department" class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-emerald-500/10 shadow-inner appearance-none text-slate-700 italic cursor-pointer">
                                    <option value="Elevage" {{ old('department', $employee->department) == 'Elevage' ? 'selected' : '' }}>{{ __("Élevage & Production") }}</option>
                                    <option value="Administration" {{ old('department', $employee->department) == 'Administration' ? 'selected' : '' }}>{{ __("Administration") }}</option>
                                    <option value="Logistique" {{ old('department', $employee->department) == 'Logistique' ? 'selected' : '' }}>{{ __("Logistique") }}</option>
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Salaire de base") }} ({{ setting('general.currency', 'GNF') }})</label>
                                <input type="number" min="0" name="salary" value="{{ old('salary', $employee->salary) }}" class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-emerald-500/10 shadow-inner text-emerald-700 text-xl tracking-tighter italic">
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("N° Orange Money") }}</label>
                                <input type="text" name="orange_money_number" value="{{ old('orange_money_number', $employee->orange_money_number) }}" placeholder="+224 6XX..."
                                    class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-orange-500/10 shadow-inner text-slate-700 italic">
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Bâtiment assigné") }}</label>
                                <select name="assigned_building_id" class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-emerald-500/10 shadow-inner appearance-none text-slate-700 italic cursor-pointer">
                                    <option value="">{{ __("Aucun (polyvalent)") }}</option>
                                    @foreach(\App\Models\Building::physical()->orderBy('name')->get() as $b)
                                        <option value="{{ $b->id }}" {{ old('assigned_building_id', $employee->assigned_building_id) == $b->id ? 'selected' : '' }}>
                                            {{ $b->name }} ({{ $b->type }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Statut RH") }}</label>
                                <select name="status" class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-emerald-500/10 shadow-inner appearance-none text-slate-700 italic cursor-pointer">
                                    <option value="Actif" {{ old('status', $employee->status) == 'Actif' ? 'selected' : '' }}>🟢 {{ __("Actif / Présent") }}</option>
                                    <option value="Suspendu" {{ old('status', $employee->status) == 'Suspendu' ? 'selected' : '' }}>🔴 {{ __("Suspendu") }}</option>
                                    <option value="Congé" {{ old('status', $employee->status) == 'Congé' ? 'selected' : '' }}>🟡 {{ __("En Congé") }}</option>
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-1 italic tracking-widest">{{ __("Date d'embauche") }}</label>
                                <input type="date" name="hire_date" value="{{ old('hire_date', $employee->hire_date ? $employee->hire_date->format('Y-m-d') : '') }}" class="w-full p-5 bg-slate-50 rounded-2xl font-black outline-none border-none focus:ring-4 focus:ring-emerald-500/10 shadow-inner text-slate-700 text-center italic">
                            </div>
                        </div>
                    </div>

                    {{-- 03. DOCUMENTS --}}
                    <div class="bg-slate-900 p-10 rounded-[3rem] shadow-xl text-white relative overflow-hidden text-left italic">
                        <div class="absolute right-0 top-0 opacity-10 p-4"><i class="fas fa-file-signature text-8xl"></i></div>
                        
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10">
                            <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest italic relative z-10">{{ __("03. Documents de bord") }}</h3>
                            <div class="flex gap-2 relative z-10">
                                @if($employee->photo_path) <span class="text-[7px] bg-blue-500 text-white px-2 py-1 rounded-md font-black uppercase tracking-tighter shadow-sm"><i class="fas fa-image mr-1"></i> {{ __("Photo Actuelle") }}</span> @endif
                                @if($employee->cv_path) <span class="text-[7px] bg-amber-500 text-slate-900 px-2 py-1 rounded-md font-black uppercase tracking-tighter shadow-sm"><i class="fas fa-file-pdf mr-1"></i> {{ __("CV Présent") }}</span> @endif
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 relative z-10">
                            <div class="border-2 border-dashed border-slate-700 p-10 rounded-[2.5rem] text-center hover:border-blue-500 transition-all bg-white/5 group">
                                <label class="block text-[9px] font-black text-slate-400 uppercase mb-6 tracking-widest italic group-hover:text-blue-400 leading-none">{{ __("Remplacer la photo de profil") }}</label>
                                <input type="file" name="photo" accept="image/*" class="text-[9px] text-slate-500 file:mr-4 file:py-2 file:px-6 file:rounded-full file:border-0 file:text-[9px] file:font-black file:bg-blue-600 file:text-white cursor-pointer italic font-black uppercase">
                            </div>
                            <div class="border-2 border-dashed border-slate-700 p-10 rounded-[2.5rem] text-center hover:border-amber-500 transition-all bg-white/5 group">
                                <label class="block text-[9px] font-black text-slate-400 uppercase mb-6 tracking-widest italic group-hover:text-amber-400 leading-none">{{ __("Mettre à jour le CV (PDF)") }}</label>
                                <input type="file" name="cv" accept=".pdf" class="text-[9px] text-slate-500 file:mr-4 file:py-2 file:px-6 file:rounded-full file:border-0 file:text-[9px] file:font-black file:bg-amber-500 file:text-slate-900 cursor-pointer italic font-black uppercase">
                            </div>
                        </div>
                    </div>

                    {{-- ACTIONS --}}
                    <div class="flex flex-col md:flex-row gap-6">
                        <a href="{{ route('employees.show', $employee->id) }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-8 rounded-[3rem] shadow-sm hover:bg-rose-50 hover:text-rose-600 transition-all text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-3 no-underline">
                            <i class="fas fa-times"></i> {{ __("Abandonner les modifications") }}
                        </a>

                        <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-8 rounded-[3rem] shadow-2xl hover:bg-blue-600 transition-all uppercase tracking-[0.2em] text-[10px] italic group shadow-blue-900/10">
                            <i class="fas fa-save mr-3 group-hover:scale-110 transition-transform"></i>
                            {{ __("Mettre à jour le profil collaborateur") }}
                        </button>
                    </div>

                    <div class="pt-8 border-t border-slate-100">
                       <p class="text-center text-[8px] font-black text-slate-300 uppercase mt-4 tracking-widest italic leading-none">
                           {{ __("Dernière modification technique :") }} {{ $employee->updated_at->format('d/m/Y \à H:i') }}
                       </p>
                    </div>
                </form>
            @else
                {{-- Accès Refusé --}}
                <div class="bg-white p-24 rounded-[4rem] border border-slate-100 shadow-xl text-center italic font-black">
                    <i class="fas fa-lock text-slate-200 text-7xl mb-10"></i>
                    <h3 class="text-2xl font-black text-slate-800 uppercase italic mb-4 tracking-tighter">{{ __("Action Restreinte") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest max-w-sm mx-auto leading-relaxed">
                        {{ __("Vous ne disposez pas des permissions suffisantes (M) pour modifier les fiches du personnel.") }}
                    </p>
                    <a href="{{ route('employees.show', $employee->id) }}" class="inline-block mt-12 px-12 py-5 bg-slate-900 text-white rounded-3xl text-[10px] font-black uppercase italic no-underline hover:bg-blue-600 transition-all shadow-xl">
                        {{ __("Retourner à la fiche agent") }}
                    </a>
                </div>
            @endcan

        </div>
    </div>
</x-app-layout>