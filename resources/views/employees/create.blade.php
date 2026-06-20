<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="text-left">
                <h2 class="text-2xl font-black uppercase tracking-tighter text-slate-800 italic leading-none">{{ __('Recrutement Personnel') }}</h2>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mt-2 italic leading-none">{{ __("Ajouter un nouveau membre à l'équipe") }}</p>
            </div>
            <a href="{{ route('employees.index') }}" class="group flex items-center px-6 py-3 bg-white border border-slate-200 text-slate-500 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-rose-50 hover:text-rose-600 transition-all shadow-sm no-underline">
                <i class="fas fa-times mr-2 group-hover:rotate-90 transition-transform"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold">
            
            {{-- Permission C : Accès à la création --}}
            @can('annuaire.C')
                @if ($errors->any())
                    <div class="mb-8 p-6 bg-rose-600 text-white rounded-[2.5rem] shadow-xl animate-pulse text-left">
                        <p class="text-[10px] font-black uppercase italic mb-2">❌ {{ __("Erreurs de validation :") }}</p>
                        <ul class="list-disc list-inside text-xs font-black uppercase tracking-tight">
                            @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('employees.store') }}" method="POST" enctype="multipart/form-data" class="space-y-8" id="recruitment-form">
                    @csrf
                    
                    {{-- 01. Infos Personnelles --}}
                    <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 relative overflow-hidden text-left">
                        <div class="absolute top-0 left-0 w-2 h-full bg-blue-500"></div>
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-10 italic">{{ __("01. Identité de l'agent") }}</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Nom de famille") }}</label>
                                <input type="text" name="last_name" value="{{ old('last_name') }}" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all outline-none uppercase shadow-inner font-black text-slate-800 italic" required>
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Prénom(s)") }}</label>
                                <input type="text" name="first_name" value="{{ old('first_name') }}" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-blue-500/10 focus:bg-white transition-all outline-none shadow-inner font-black text-slate-800 italic" required>
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Genre") }}</label>
                                <div class="grid grid-cols-2 gap-4">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="gender" value="M" class="peer sr-only" {{ old('gender', 'M') == 'M' ? 'checked' : '' }}>
                                        <div class="p-5 text-center bg-slate-50 rounded-2xl font-black text-[10px] uppercase text-slate-400 peer-checked:bg-blue-600 peer-checked:text-white transition-all shadow-inner italic">{{ __("Homme") }}</div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="gender" value="F" class="peer sr-only" {{ old('gender') == 'F' ? 'checked' : '' }}>
                                        <div class="p-5 text-center bg-slate-50 rounded-2xl font-black text-[10px] uppercase text-slate-400 peer-checked:bg-pink-600 peer-checked:text-white transition-all shadow-inner italic">{{ __("Femme") }}</div>
                                    </label>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Contact Téléphonique") }}</label>
                                <input type="text" name="phone" value="{{ old('phone') }}" placeholder="+224..." class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-blue-500/10 outline-none shadow-inner font-black text-slate-800 italic" required>
                            </div>
                        </div>
                    </div>

                    {{-- 02. Contrat & Poste --}}
                    <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 relative overflow-hidden text-left">
                        <div class="absolute top-0 left-0 w-2 h-full bg-emerald-500"></div>
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-10 italic">{{ __("02. Poste & Contrat") }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Poste occupé") }}</label>
                                <input type="text" name="job_title" value="{{ old('job_title') }}" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner font-black text-slate-800 italic" required>
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Type de Contrat") }}</label>
                                <select name="contract_type" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner appearance-none font-black text-slate-800 italic cursor-pointer" required>
                                    <option value="CDI" {{ old('contract_type') == 'CDI' ? 'selected' : '' }}>📄 {{ __("CDI (Indéterminé)") }}</option>
                                    <option value="CDD" {{ old('contract_type') == 'CDD' ? 'selected' : '' }}>⏳ {{ __("CDD (Déterminé)") }}</option>
                                    <option value="Journalier" {{ old('contract_type') == 'Journalier' ? 'selected' : '' }}>☀️ {{ __("Journalier") }}</option>
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Département") }}</label>
                                <select name="department" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner appearance-none font-black text-slate-800 italic cursor-pointer">
                                    <option value="Elevage" {{ old('department') == 'Elevage' ? 'selected' : '' }}>🐔 {{ __("Élevage / Technique") }}</option>
                                    <option value="Administration" {{ old('department') == 'Administration' ? 'selected' : '' }}>📂 {{ __("Administration / RH") }}</option>
                                    <option value="Logistique" {{ old('department') == 'Logistique' ? 'selected' : '' }}>🚚 {{ __("Logistique & Ventes") }}</option>
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Salaire Mensuel") }} ({{ setting('general.currency', 'GNF') }})</label>
                                <input type="number"  name="salary" min="0" value="{{ old('salary') }}" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner font-black text-emerald-600 text-xl italic">
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("N° Orange Money") }}</label>
                                <input type="text" name="orange_money_number" value="{{ old('orange_money_number') }}" placeholder="+224 6XX..."
                                    class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-orange-500/10 outline-none shadow-inner font-black text-slate-800 italic">
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Bâtiment assigné") }}</label>
                                <select name="assigned_building_id" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner appearance-none font-black text-slate-800 italic cursor-pointer">
                                    <option value="">{{ __("Aucun (polyvalent)") }}</option>
                                    @foreach(\App\Models\Building::physical()->orderBy('name')->get() as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->type }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="space-y-3">
                                <label class="text-[10px] font-black text-slate-500 uppercase ml-1 tracking-widest">{{ __("Date d'embauche effective") }}</label>
                                <input type="date" name="hire_date" value="{{ old('hire_date', date('Y-m-d')) }}" class="w-full p-5 bg-slate-50 rounded-2xl border-none focus:ring-4 focus:ring-emerald-500/10 outline-none shadow-inner text-center font-black italic text-slate-800" required>
                            </div>
                        </div>
                    </div>

                    {{-- 03. Documents --}}
                    <div class="bg-slate-900 p-10 rounded-[3rem] shadow-2xl text-white text-left italic">
                        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-10 italic">{{ __("03. Documents & Profil") }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div class="p-10 border-2 border-dashed border-slate-700 rounded-[2.5rem] bg-white/5 text-center group hover:border-blue-500 transition-all">
                                <i class="fas fa-camera text-slate-600 mb-4 text-2xl group-hover:text-blue-500"></i>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-6 tracking-widest">{{ __("Photo d'Identité") }}</label>
                                <input type="file" name="photo" accept="image/*" class="text-[10px] text-slate-500 file:bg-blue-600 file:text-white file:rounded-full file:border-0 file:px-6 file:py-2 file:font-black file:uppercase file:mr-4 cursor-pointer">
                            </div>
                            <div class="p-10 border-2 border-dashed border-slate-700 rounded-[2.5rem] bg-white/5 text-center group hover:border-emerald-500 transition-all">
                                <i class="fas fa-file-pdf text-slate-600 mb-4 text-2xl group-hover:text-emerald-500"></i>
                                <label class="block text-[10px] font-black uppercase text-slate-400 mb-6 tracking-widest">{{ __("Dossier PDF (Contrat/CV)") }}</label>
                                <input type="file" name="cv" accept=".pdf" class="text-[10px] text-slate-500 file:bg-emerald-500 file:text-white file:rounded-full file:border-0 file:px-6 file:py-2 file:font-black file:uppercase file:mr-4 cursor-pointer">
                            </div>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex flex-col md:flex-row gap-4 pt-8">
                        <a href="{{ route('employees.index') }}" class="flex-1 bg-white border-2 border-slate-100 text-slate-400 font-black py-7 rounded-[2.5rem] shadow-sm hover:bg-slate-50 transition-all text-center uppercase tracking-widest text-[10px] italic flex items-center justify-center gap-3 no-underline">
                            <i class="fas fa-arrow-left"></i> {{ __("Retour") }}
                        </a>
                        <button type="submit" class="flex-[2] bg-slate-900 text-white font-black py-7 rounded-[2.5rem] hover:bg-blue-600 transition-all uppercase tracking-[0.3em] text-[10px] italic shadow-2xl group">
                            <i class="fas fa-user-plus mr-3 group-hover:scale-110 transition-transform"></i>
                            {{ __("Enregistrer le nouveau collaborateur") }}
                        </button>
                    </div>
                </form>
            @else
                {{-- Accès Refusé --}}
                <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic">
                    <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                    <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2">{{ __("Accès Restreint") }}</h3>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest">{{ __("La permission") }} <span class="text-blue-500">rh.C</span> {{ __("(Créer) est requise pour recruter du personnel.") }}</p>
                    <a href="{{ route('employees.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline hover:bg-blue-600 transition-all">{{ __("Retour à la liste") }}</a>
                </div>
            @endcan

        </div>
    </div>
</x-app-layout>