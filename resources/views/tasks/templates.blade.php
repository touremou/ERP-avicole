<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <a href="{{ route('tasks.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Templates de Tâches") }}</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">{{ __("Configuration des routines automatisées") }}</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold" x-data="{ showForm: false }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="mb-6 p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg flex items-center italic bg-emerald-500 text-white">
                    <i class="fa-solid fa-check-double mr-3"></i> {{ session('success') }}
                </div>
            @endif

            {{-- BOUTON + FORMULAIRE CRÉATION --}}
            <div class="mb-6">
                <button @click="showForm = !showForm" class="w-full bg-white border-2 border-dashed border-slate-200 hover:border-indigo-400 rounded-2xl p-4 text-[10px] font-black text-slate-400 hover:text-indigo-600 uppercase tracking-widest transition-all cursor-pointer" :class="showForm && 'border-indigo-400 text-indigo-600'">
                    <i class="fa-solid fa-plus mr-2"></i> <span x-text="showForm ? '{{ __("Masquer le formulaire") }}' : '{{ __("Créer un nouveau template") }}'"></span>
                </button>

                <div x-show="showForm" x-collapse class="mt-4">
                    <form method="POST" action="{{ route('tasks.templates.store') }}" class="bg-white p-6 rounded-2xl border border-indigo-100 shadow-sm space-y-4 text-left">
                        @csrf
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="md:col-span-2">
                                <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Nom de la tâche") }}</label>
                                <input type="text" name="name" required placeholder="{{ __("Ex: Alimentation matin, Pesée hebdo...") }}" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                            </div>
                            <div>
                                <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Catégorie") }}</label>
                                <select name="category" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none">
                                    <option value="alimentation">🌾 {{ __("Alimentation") }}</option>
                                    <option value="collecte">🥚 {{ __("Collecte") }}</option>
                                    <option value="controle">📋 {{ __("Contrôle") }}</option>
                                    <option value="nettoyage">🧹 {{ __("Nettoyage") }}</option>
                                    <option value="sante">💉 {{ __("Santé") }}</option>
                                    <option value="maintenance">🔧 {{ __("Maintenance") }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Fréquence") }}</label>
                                <select name="frequency" required class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                                    <option value="quotidien">{{ __("Quotidien") }}</option>
                                    <option value="hebdo">{{ __("Hebdomadaire") }}</option>
                                    <option value="mensuel">{{ __("Mensuel") }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Heure") }}</label>
                                <input type="time" name="scheduled_time" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                            </div>
                            <div>
                                <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Durée") }}</label>
                                <input type="number" name="duration_minutes" value="30" min="5" max="480" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none text-center">
                            </div>
                            <div>
                                <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Priorité") }}</label>
                                <select name="priority" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                                    <option value="normale">{{ __("Normale") }}</option>
                                    <option value="haute">{{ __("Haute") }}</option>
                                    <option value="critique">{{ __("Critique") }}</option>
                                    <option value="basse">{{ __("Basse") }}</option>
                                </select>
                            </div>
                        </div>

                        {{-- JOURS --}}
                        <div>
                            <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-2">{{ __("Jours d'exécution") }}</label>
                            <div class="flex gap-2">
                                @foreach(['1' => __('Lun'), '2' => __('Mar'), '3' => __('Mer'), '4' => __('Jeu'), '5' => __('Ven'), '6' => __('Sam'), '7' => __('Dim')] as $n => $label)
                                <label class="cursor-pointer">
                                    <input type="checkbox" name="days_of_week[]" value="{{ $n }}" {{ $n <= 6 ? 'checked' : '' }} class="hidden peer">
                                    <div class="w-10 h-10 rounded-xl flex items-center justify-center text-[9px] font-black uppercase bg-slate-50 text-slate-400 peer-checked:bg-indigo-500 peer-checked:text-white transition-all shadow-inner">{{ $label }}</div>
                                </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl cursor-pointer">
                                <input type="checkbox" name="per_building" value="1" checked class="rounded text-indigo-500">
                                <span class="text-[9px] font-black text-slate-600 uppercase">{{ __("Générer par bâtiment actif") }}</span>
                            </label>
                            <div>
                                <label class="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1">{{ __("Types de lots (optionnel)") }}</label>
                                <div class="flex gap-2">
                                    @foreach(['ponte', 'chair', 'reproducteur', 'poussiniere'] as $t)
                                    <label class="cursor-pointer">
                                        <input type="checkbox" name="batch_types[]" value="{{ $t }}" class="hidden peer">
                                        <div class="px-3 py-1.5 rounded-lg text-[8px] font-black uppercase bg-slate-50 text-slate-400 peer-checked:bg-purple-100 peer-checked:text-purple-600 transition-all">{{ $t }}</div>
                                    </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <textarea name="description" rows="2" placeholder="{{ __("Description optionnelle...") }}" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-bold shadow-inner outline-none"></textarea>

                        <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-black text-[9px] uppercase tracking-widest hover:bg-indigo-700 border-none cursor-pointer shadow-lg italic">
                            <i class="fa-solid fa-plus mr-1"></i> {{ __("Créer le template") }}
                        </button>
                    </form>
                </div>
            </div>

            {{-- LISTE DES TEMPLATES --}}
            @php
                $grouped = $templates->groupBy('category');
                $catMeta = [
                    'alimentation' => ['label' => __('Alimentation'),  'icon' => 'fa-bowl-food',        'color' => 'amber'],
                    'collecte'     => ['label' => __('Collecte'),      'icon' => 'fa-egg',              'color' => 'emerald'],
                    'controle'     => ['label' => __('Contrôles'),     'icon' => 'fa-clipboard-check',  'color' => 'blue'],
                    'nettoyage'    => ['label' => __('Nettoyage'),     'icon' => 'fa-broom',            'color' => 'purple'],
                    'sante'        => ['label' => __('Santé'),         'icon' => 'fa-heart-pulse',      'color' => 'rose'],
                    'maintenance'  => ['label' => __('Maintenance'),   'icon' => 'fa-wrench',           'color' => 'slate'],
                ];
                $dayLabels = [1 => __('L'), 2 => __('M'), 3 => __('Me'), 4 => __('J'), 5 => __('V'), 6 => __('S'), 7 => __('D')];
            @endphp

            <div class="space-y-5 text-left">
                @foreach($grouped as $cat => $items)
                @php $meta = $catMeta[$cat] ?? ['label' => $cat, 'icon' => 'fa-circle', 'color' => 'slate']; @endphp
                <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 bg-{{ $meta['color'] }}-50 border-b border-{{ $meta['color'] }}-100 flex items-center gap-3">
                        <i class="fa-solid {{ $meta['icon'] }} text-{{ $meta['color'] }}-500"></i>
                        <h3 class="text-[9px] font-black text-{{ $meta['color'] }}-700 uppercase tracking-widest">{{ $meta['label'] }}</h3>
                        <span class="text-[7px] font-black text-{{ $meta['color'] }}-400 bg-white px-2 py-0.5 rounded-full">{{ $items->count() }}</span>
                    </div>
                    <div class="divide-y divide-slate-50">
                        @foreach($items as $tpl)
                        <div class="px-5 py-3 flex items-center gap-3 {{ !$tpl->is_active ? 'opacity-30' : '' }}">
                            {{-- INFO --}}
                            <div class="flex-1 min-w-0">
                                <p class="text-[10px] font-black text-slate-800 uppercase">{{ $tpl->name }}</p>
                                <div class="flex items-center gap-2 mt-1 text-[7px] text-slate-400 font-black">
                                    @if($tpl->scheduled_time)<span><i class="fa-solid fa-clock mr-0.5"></i> {{ \Carbon\Carbon::parse($tpl->scheduled_time)->format('H:i') }}</span>@endif
                                    <span>{{ $tpl->duration_minutes }}min</span>
                                    <span class="uppercase text-blue-400">{{ $tpl->frequency }}</span>
                                    @if($tpl->per_building)<span class="text-indigo-400">×bât.</span>@endif
                                    @if($tpl->batch_types)<span class="text-purple-400">{{ implode(',', $tpl->batch_types) }}</span>@endif
                                </div>
                            </div>

                            {{-- JOURS --}}
                            <div class="flex gap-0.5 shrink-0">
                                @foreach($dayLabels as $n => $label)
                                    @php $active = $tpl->days_of_week === null || in_array($n, $tpl->days_of_week ?? []); @endphp
                                    <div @class(['w-5 h-5 rounded text-[6px] font-black flex items-center justify-center',
                                        'bg-indigo-500 text-white' => $active,
                                        'bg-slate-50 text-slate-300' => !$active])>{{ $label }}</div>
                                @endforeach
                            </div>

                            {{-- PRIORITÉ --}}
                            <span @class(['text-[7px] font-black uppercase px-2 py-0.5 rounded-full shrink-0',
                                'bg-red-100 text-red-600' => $tpl->priority === 'critique',
                                'bg-amber-100 text-amber-600' => $tpl->priority === 'haute',
                                'bg-slate-100 text-slate-400' => $tpl->priority === 'normale',
                                'bg-slate-50 text-slate-300' => $tpl->priority === 'basse'])>{{ $tpl->priority }}</span>

                            {{-- ACTIONS --}}
                            <div class="flex items-center gap-1.5 shrink-0">
                                <a href="{{ route('tasks.templates.edit', $tpl) }}" class="w-7 h-7 rounded-lg bg-slate-50 text-slate-400 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-center no-underline transition-all" title="{{ __("Modifier") }}"><i class="fa-solid fa-pen text-[8px]"></i></a>
                                <form method="POST" action="{{ route('tasks.templates.toggle', $tpl) }}">@csrf
                                    <button class="w-7 h-7 rounded-lg flex items-center justify-center border-none cursor-pointer transition-all {{ $tpl->is_active ? 'bg-emerald-50 text-emerald-500 hover:bg-red-50 hover:text-red-500' : 'bg-slate-50 text-slate-300 hover:bg-emerald-50 hover:text-emerald-500' }}" title="{{ $tpl->is_active ? __('Désactiver') : __('Activer') }}">
                                        <i class="fa-solid {{ $tpl->is_active ? 'fa-toggle-on' : 'fa-toggle-off' }} text-[10px]"></i>
                                    </button>
                                </form>
                                @can('admin.S')
                                <form method="POST" action="{{ route('tasks.templates.destroy', $tpl) }}" onsubmit="return confirm('{{ __("Supprimer ce template ?") }}')">@csrf @method('DELETE')
                                    <button class="w-7 h-7 rounded-lg bg-transparent text-slate-200 hover:text-red-500 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __("Supprimer") }}"><i class="fa-solid fa-trash-can text-[8px]"></i></button>
                                </form>
                                @endcan
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
