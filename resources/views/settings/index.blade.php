<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-slate-900 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-sliders text-lg"></i></div>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">Paramètres Système</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">Configuration globale de l'ERP</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            <div class="flex flex-col lg:flex-row gap-6">

                {{-- SIDEBAR GROUPES --}}
                <div class="lg:w-56 shrink-0">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3 space-y-1 lg:sticky lg:top-20">
                        @foreach($groups as $gKey => $g)
                            <a href="{{ route('settings.index', ['group' => $gKey]) }}"
                               @class(['flex items-center gap-2.5 px-4 py-3 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all no-underline',
                                   "bg-{$g['color']}-50 text-{$g['color']}-600" => $activeGroup === $gKey,
                                   'text-slate-400 hover:bg-slate-50 hover:text-slate-700' => $activeGroup !== $gKey])>
                                <i class="fa-solid {{ $g['icon'] }} w-4 text-center"></i>
                                {{ $g['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>

                {{-- FORMULAIRE PARAMÈTRES --}}
                <div class="flex-1">
                    <form method="POST" action="{{ route('settings.update') }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="_group" value="{{ $activeGroup }}">

                        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                                <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500 flex items-center gap-2">
                                    <i class="fa-solid {{ $groups[$activeGroup]['icon'] ?? 'fa-cog' }} text-{{ $groups[$activeGroup]['color'] ?? 'slate' }}-500"></i>
                                    {{ $groups[$activeGroup]['label'] ?? $activeGroup }}
                                </h3>
                                <button type="submit" class="bg-slate-900 text-white px-6 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer shadow-lg italic">
                                    <i class="fa-solid fa-save mr-1"></i> Sauvegarder
                                </button>
                            </div>

                            <div class="divide-y divide-slate-50">
                                @foreach($settings as $s)
                                <div class="px-6 py-5 flex flex-col md:flex-row md:items-center gap-3 hover:bg-slate-50/50 transition-all">
                                    {{-- Label + description --}}
                                    <div class="md:w-1/3">
                                        <label class="text-[10px] font-black text-slate-700 uppercase tracking-widest block">{{ $s->label ?? $s->key }}</label>
                                        @if($s->description)
                                            <p class="text-[8px] text-slate-400 mt-0.5 normal-case">{{ $s->description }}</p>
                                        @endif
                                    </div>

                                    {{-- Input dynamique selon le type --}}
                                    <div class="md:flex-1 flex items-center gap-2">
                                        @switch($s->type)
                                            @case('number')
                                                <input type="number" name="settings[{{ $s->key }}]" value="{{ $s->value }}" step="any"
                                                    class="w-full md:w-48 bg-slate-50 border-none rounded-xl p-3 text-sm font-black text-slate-800 shadow-inner outline-none text-right focus:ring-2 focus:ring-blue-500">
                                                @if($s->unit)
                                                    <span class="text-[9px] font-black text-slate-400 uppercase whitespace-nowrap">{{ $s->unit }}</span>
                                                @endif
                                                @break

                                            @case('boolean')
                                                <label class="relative inline-flex items-center cursor-pointer">
                                                    <input type="hidden" name="settings[{{ $s->key }}]" value="0">
                                                    <input type="checkbox" name="settings[{{ $s->key }}]" value="1" {{ $s->value ? 'checked' : '' }}
                                                        class="sr-only peer">
                                                    <div class="w-11 h-6 bg-slate-200 peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-emerald-500 after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                                                </label>
                                                @break

                                            @case('select')
                                                <select name="settings[{{ $s->key }}]"
                                                    class="w-full md:w-48 bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none cursor-pointer focus:ring-2 focus:ring-blue-500 italic">
                                                    @foreach(explode(',', $s->options ?? '') as $opt)
                                                        <option value="{{ trim($opt) }}" {{ $s->value === trim($opt) ? 'selected' : '' }}>{{ trim($opt) }}</option>
                                                    @endforeach
                                                </select>
                                                @break

                                            @case('password')
                                                <input type="password" name="settings[{{ $s->key }}]" placeholder="••••••••"
                                                    class="w-full md:w-64 bg-slate-50 border-none rounded-xl p-3 text-sm font-black shadow-inner outline-none focus:ring-2 focus:ring-blue-500">
                                                <span class="text-[8px] text-slate-400">Laisser vide = garder l'actuel</span>
                                                @break

                                            @case('textarea')
                                                <textarea name="settings[{{ $s->key }}]" rows="2"
                                                    class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-bold shadow-inner outline-none focus:ring-2 focus:ring-blue-500">{{ $s->value }}</textarea>
                                                @break

                                            @default
                                                <input type="text" name="settings[{{ $s->key }}]" value="{{ $s->is_sensitive ? '' : $s->value }}"
                                                    placeholder="{{ $s->is_sensitive ? '••••••••' : '' }}"
                                                    class="w-full md:w-64 bg-slate-50 border-none rounded-xl p-3 text-sm font-black text-slate-800 shadow-inner outline-none focus:ring-2 focus:ring-blue-500">
                                                @if($s->unit)
                                                    <span class="text-[9px] font-black text-slate-400 uppercase whitespace-nowrap">{{ $s->unit }}</span>
                                                @endif
                                        @endswitch
                                    </div>
                                </div>
                                @endforeach
                            </div>

                            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
                                <button type="submit" class="bg-emerald-500 text-white px-8 py-3 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer shadow-lg italic">
                                    <i class="fa-solid fa-save mr-1"></i> Sauvegarder les modifications
                                </button>
                            </div>
                        </div>
                    </form>

                    {{-- LIEN VERS LES LOGS --}}
                    <div class="mt-6 text-center">
                        <a href="{{ route('settings.logs') }}" class="text-[9px] font-black text-slate-400 uppercase tracking-widest hover:text-blue-600 no-underline transition-all">
                            <i class="fa-solid fa-clock-rotate-left mr-1"></i> Journal des modifications
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
