<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 text-left">
            <div>
                <h2 class="text-2xl font-black text-slate-800 tracking-tighter uppercase italic leading-none">{{ __("Gestion des Accès & Rôles") }}</h2>
                <p class="text-slate-500 font-bold text-[10px] uppercase tracking-[0.2em] mt-2 italic">{{ __("RBAC par Module — Matrice des privilèges industrielle") }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <button onclick="document.getElementById('moduleMatrixModal').classList.remove('hidden')"
                        class="bg-indigo-500 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-indigo-600 transition-all shadow-lg italic border-none cursor-pointer">
                    <i class="fas fa-th mr-2"></i> {{ __("Matrice Modules") }}
                </button>
                <button onclick="document.getElementById('roleConfigModal').classList.remove('hidden')"
                        class="bg-white border border-slate-200 text-slate-600 px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:border-slate-900 transition-all shadow-sm italic cursor-pointer">
                    <i class="fas fa-shield-halved mr-2 text-blue-500"></i> {{ __("Rôles & LCMS") }}
                </button>
                <button onclick="document.getElementById('userModal').classList.remove('hidden')"
                        class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-xl italic border-none cursor-pointer">
                    <i class="fas fa-user-plus mr-2"></i> {{ __("Nouvel Utilisateur") }}
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-10 italic">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-8 p-5 rounded-[2rem] font-black text-[10px] uppercase tracking-[0.2em] shadow-xl flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3 text-lg"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- TABLEAU DES UTILISATEURS --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden text-left font-bold">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-8 py-5 text-left">{{ __("Utilisateur") }}</th>
                            <th class="px-6 py-5 text-left">{{ __("Rôle") }}</th>
                            <th class="px-6 py-5 text-center">{{ __("Permissions globales") }}</th>
                            <th class="px-6 py-5 text-center">{{ __("Modules accessibles") }}</th>
                            <th class="px-8 py-5 text-right">{{ __("Actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($users as $user)
                        <tr class="hover:bg-slate-50/50 transition-all group">
                            <td class="px-8 py-5">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-2xl bg-slate-100 flex items-center justify-center font-black text-slate-400 uppercase shadow-inner group-hover:bg-slate-900 group-hover:text-white transition-all text-sm italic">
                                        {{ substr($user->name, 0, 2) }}
                                    </div>
                                    <div>
                                        <p class="font-black text-slate-900 tracking-tighter text-sm leading-none mb-1 uppercase">{{ $user->name }}</p>
                                        <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest italic">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <form action="{{ route('users.update_role', $user->id) }}" method="POST">
                                    @csrf @method('PATCH')
                                    <select name="role_id" onchange="this.form.submit()"
                                        class="appearance-none text-[9px] font-black uppercase border-none bg-slate-50 rounded-xl px-4 py-2 shadow-inner italic cursor-pointer text-slate-700 outline-none">
                                        <option value="">{{ __("NON ASSIGNÉ") }}</option>
                                        @foreach($roles as $role)
                                            <option value="{{ $role->id }}" {{ $user->role_id == $role->id ? 'selected' : '' }}>
                                                {{ $role->icon }} {{ $role->display_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </form>
                            </td>
                            <td class="px-6 py-5">
                                <div class="flex justify-center gap-1.5">
                                    @foreach(['L', 'C', 'M', 'S'] as $priv)
                                        @php $has = $user->hasPermission($priv); @endphp
                                        <div @class(['w-7 h-7 rounded-lg text-[9px] flex items-center justify-center font-black transition-all',
                                            'bg-emerald-100 text-emerald-600' => $has && $priv === 'L',
                                            'bg-orange-100 text-orange-600' => $has && $priv === 'C',
                                            'bg-blue-100 text-blue-600' => $has && $priv === 'M',
                                            'bg-rose-100 text-rose-600' => $has && $priv === 'S',
                                            'bg-slate-50 text-slate-200' => !$has])>
                                            {{ $has ? $priv : '·' }}
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-6 py-5 text-center">
                                @php $accessibleCount = $user->getAccessibleModules()->count(); @endphp
                                <span class="text-xs font-black {{ $accessibleCount >= 10 ? 'text-emerald-600' : ($accessibleCount >= 5 ? 'text-blue-600' : 'text-slate-400') }}">
                                    {{ $accessibleCount }}/{{ $modules->count() }}
                                </span>
                                <p class="text-[7px] text-slate-300 uppercase">{{ __("modules") }}</p>
                            </td>
                            <td class="px-8 py-5 text-right">
                                @if(auth()->id() !== $user->id)
                                <form action="{{ route('users.destroy', $user->id) }}" method="POST" onsubmit="return confirm('{{ __("Supprimer cet accès ?") }}')">
                                    @csrf @method('DELETE')
                                    <button class="w-10 h-10 inline-flex items-center justify-center rounded-xl text-slate-300 hover:text-rose-600 hover:bg-rose-50 transition-all border-none bg-transparent cursor-pointer">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                </form>
                                @else
                                    <span class="px-3 py-1 bg-slate-100 rounded-lg text-[7px] font-black text-slate-400 uppercase italic">{{ __("Moi") }}</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-8 py-4">{{ $users->links() }}</div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- MODAL : MATRICE MODULES × RÔLES (NOUVEAU)                     --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div id="moduleMatrixModal" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[3rem] w-full max-w-6xl shadow-2xl overflow-hidden text-left italic font-bold max-h-[90vh] flex flex-col">
            <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center shrink-0">
                <div>
                    <h3 class="text-2xl font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ __("Matrice des Modules") }}</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-widest mt-2">{{ __("Permissions L/C/M/S par module et par rôle") }}</p>
                </div>
                <button onclick="document.getElementById('moduleMatrixModal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900 border-none bg-transparent cursor-pointer text-xl"><i class="fas fa-times"></i></button>
            </div>

            <form action="{{ route('roles.update_module_matrix') }}" method="POST" class="flex-1 overflow-auto">
                @csrf
                <div class="px-10 py-6 overflow-x-auto">
                    <table class="w-full border-collapse min-w-[800px]">
                        <thead class="sticky top-0 bg-white z-10">
                            <tr>
                                <th class="text-left px-4 py-3 text-[9px] font-black text-slate-400 uppercase tracking-widest w-40">{{ __("Module") }}</th>
                                @foreach($roles as $role)
                                <th class="text-center px-2 py-3 min-w-[120px]">
                                    <span class="text-lg">{{ $role->icon }}</span>
                                    <p class="text-[8px] font-black text-slate-700 uppercase mt-1">{{ $role->display_name }}</p>
                                    <p class="text-[7px] text-slate-300">{{ $role->users_count }} {{ __("membres") }}</p>
                                </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach($modules as $module)
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-{{ $module->color }}-50 text-{{ $module->color }}-500 flex items-center justify-center">
                                            <i class="fa-solid {{ $module->icon }} text-[10px]"></i>
                                        </span>
                                        <span class="text-[9px] font-black text-slate-700 uppercase">{{ $module->name }}</span>
                                    </div>
                                </td>
                                @foreach($roles as $role)
                                <td class="px-2 py-3">
                                    <div class="flex justify-center gap-1">
                                        @foreach(['L' => 'emerald', 'C' => 'amber', 'M' => 'blue', 'S' => 'red'] as $level => $color)
                                        @php $checked = $moduleMatrix[$role->id][$module->id][$level] ?? false; @endphp
                                        <label class="cursor-pointer">
                                            <input type="checkbox"
                                                   name="module_perms[{{ $role->id }}][{{ $module->id }}][{{ $level }}]"
                                                   value="1"
                                                   @checked($checked)
                                                   class="hidden peer">
                                            <div @class([
                                                'w-7 h-7 rounded-md text-[9px] flex items-center justify-center font-black transition-all border',
                                                "peer-checked:bg-{$color}-500 peer-checked:text-white peer-checked:border-{$color}-500",
                                                "bg-slate-50 text-slate-300 border-slate-100 hover:border-{$color}-300"
                                            ])>{{ $level }}</div>
                                        </label>
                                        @endforeach
                                    </div>
                                </td>
                                @endforeach
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-10 py-6 border-t border-slate-100 bg-slate-50 shrink-0">
                    <div class="flex justify-between items-center">
                        <div class="flex gap-4">
                            <span class="text-[8px] font-black uppercase text-slate-400 flex items-center gap-1"><span class="w-4 h-4 bg-emerald-500 rounded text-white text-[8px] flex items-center justify-center">L</span> {{ __("Lecture") }}</span>
                            <span class="text-[8px] font-black uppercase text-slate-400 flex items-center gap-1"><span class="w-4 h-4 bg-amber-500 rounded text-white text-[8px] flex items-center justify-center">C</span> {{ __("Création") }}</span>
                            <span class="text-[8px] font-black uppercase text-slate-400 flex items-center gap-1"><span class="w-4 h-4 bg-blue-500 rounded text-white text-[8px] flex items-center justify-center">M</span> {{ __("Modification") }}</span>
                            <span class="text-[8px] font-black uppercase text-slate-400 flex items-center gap-1"><span class="w-4 h-4 bg-red-500 rounded text-white text-[8px] flex items-center justify-center">S</span> {{ __("Suppression") }}</span>
                        </div>
                        <button type="submit" class="bg-indigo-600 text-white px-10 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-indigo-700 transition-all border-none cursor-pointer shadow-xl italic">
                            <i class="fas fa-save mr-2"></i> {{ __("Appliquer la Matrice") }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ═══ MODAL RÔLES & LCMS GLOBAL (existant, gardé) ═══ --}}
    <div id="roleConfigModal" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-50 flex items-center justify-center p-6">
    <div class="bg-white rounded-[3rem] w-full max-w-2xl shadow-2xl overflow-hidden italic font-bold text-left max-h-[85vh] flex flex-col">
        <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center shrink-0">
            <h3 class="text-xl font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ __("Gestion des Rôles") }}</h3>
            <button onclick="document.getElementById('roleConfigModal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900 border-none bg-transparent cursor-pointer text-xl"><i class="fas fa-times"></i></button>
        </div>
        <div class="flex-1 overflow-auto p-10">
            
            {{-- CRÉATION --}}
            <form action="{{ route('roles.store') }}" method="POST" class="mb-8 p-6 bg-slate-50 rounded-2xl">
                @csrf
                <p class="text-[9px] font-black uppercase text-blue-500 mb-4 tracking-widest">{{ __("Nouveau grade") }}</p>
                <div class="flex gap-3">
                    <input type="text" name="icon" placeholder="🛠️" class="w-16 bg-white border-none rounded-xl p-3 text-center text-xl shadow-sm outline-none">
                    <input type="text" name="display_name" placeholder="{{ __('NOM DU RÔLE') }}" required class="flex-1 bg-white border-none rounded-xl p-3 text-xs font-black uppercase shadow-sm outline-none">
                    <button type="submit" class="bg-slate-900 text-white px-6 rounded-xl font-black text-[9px] uppercase hover:bg-blue-600 transition-all border-none cursor-pointer italic">{{ __("Créer") }}</button>
                </div>
            </form>

            {{-- FORMULAIRES DE SUPPRESSION CACHÉS (Pour éviter l'imbrication HTML invalide) --}}
            @foreach($roles as $role)
                @if($role->users_count == 0)
                    <form id="delete-role-{{ $role->id }}" action="{{ route('roles.destroy', $role->id) }}" method="POST" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                @endif
            @endforeach

            {{-- LISTE DES RÔLES (les droits s'éditent via la « Matrice des Modules ») --}}
            <div class="space-y-4">
                @foreach($roles as $role)
                <div class="p-6 bg-white rounded-2xl border border-slate-100 shadow-sm transition-all hover:border-slate-300">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">{{ $role->icon }}</span>
                            <span class="font-black text-slate-900 uppercase italic tracking-tighter text-sm">{{ $role->display_name }}</span>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-1 bg-slate-100 rounded-lg text-[7px] font-black text-slate-400 uppercase">{{ $role->users_count }} mbr</span>
                            
                            {{-- BOUTON SUPPRIMER (Uniquement si 0 membre) --}}
                            @if($role->users_count == 0)
                                <button type="submit" form="delete-role-{{ $role->id }}" onclick="return confirm('{{ __("Confirmer la suppression définitive de ce rôle ?") }}')"
                                        class="w-6 h-6 flex items-center justify-center bg-red-50 text-red-500 hover:bg-red-500 hover:text-white rounded-lg transition-all cursor-pointer border-none outline-none" title="{{ __('Supprimer ce rôle vide') }}">
                                    <i class="fas fa-trash-can text-[10px]"></i>
                                </button>
                            @endif
                        </div>
                    </div>

                    <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest italic">
                        <i class="fa-solid fa-table-cells mr-1"></i>{{ __("Droits gérés via la Matrice des Modules") }}
                    </p>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

    {{-- ═══ MODAL NOUVEL UTILISATEUR ═══ --}}
    <div id="userModal" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-50 flex items-center justify-center p-6">
        <div class="bg-white rounded-[3rem] w-full max-w-xl shadow-2xl overflow-hidden text-left italic font-bold">
            <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-xl font-black text-slate-800 uppercase tracking-tighter italic leading-none">{{ __("Nouvel Utilisateur") }}</h3>
                <button onclick="document.getElementById('userModal').classList.add('hidden')" class="text-slate-300 hover:text-slate-900 border-none bg-transparent cursor-pointer text-xl"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-10">
                <form action="{{ route('users.store') }}" method="POST" class="space-y-6">
                    @csrf
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 ml-2 tracking-widest">{{ __("Nom") }}</label>
                        <input type="text" name="name" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 ml-2 tracking-widest">{{ __("Email") }}</label>
                        <input type="email" name="email" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 ml-2 tracking-widest">{{ __("Mot de passe") }}</label>
                            <input type="password" name="password" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 ml-2 tracking-widest">{{ __("Confirmation") }}</label>
                            <input type="password" name="password_confirmation" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-sm shadow-inner outline-none">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[9px] font-black uppercase text-slate-400 ml-2 tracking-widest">{{ __("Rôle") }}</label>
                        <select name="role_id" required class="w-full bg-slate-50 border-none rounded-2xl p-4 font-black text-xs uppercase shadow-inner outline-none appearance-none italic cursor-pointer">
                            <option value="">{{ __("Choisir un rôle...") }}</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->icon }} {{ $role->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="w-full bg-slate-900 text-white py-5 rounded-2xl font-black text-xs uppercase tracking-[0.3em] hover:bg-blue-600 transition-all border-none cursor-pointer italic">
                        <i class="fas fa-user-plus mr-2"></i> {{ __("Créer l'accès") }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>