<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-5 text-left">
            <div class="w-14 h-14 bg-emerald-500 rounded-[1.5rem] flex items-center justify-center text-white shadow-2xl rotate-3">
                <i class="fa-brands fa-whatsapp text-2xl"></i>
            </div>
            <div>
                <h2 class="font-black text-2xl text-slate-800 leading-none uppercase italic tracking-tighter">{{ __("Notifications") }}</h2>
                <p class="text-[10px] font-black text-emerald-600 uppercase tracking-[0.2em] mt-2 italic">
                    {{ __("Configuration des alertes WhatsApp & SMS") }}
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- STATS --}}
            <div class="grid grid-cols-3 gap-4 mb-8">
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-1">{{ __("Envoyés") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $stats['total_sent'] }}</p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-red-400 uppercase tracking-widest mb-1">{{ __("Échoués") }}</p>
                    <p class="text-2xl font-black {{ $stats['total_failed'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $stats['total_failed'] }}</p>
                </div>
                <div class="bg-white p-5 rounded-[2rem] border border-slate-100 shadow-sm text-center">
                    <p class="text-[8px] font-black text-blue-400 uppercase tracking-widest mb-1">{{ __("Aujourd'hui") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $stats['today_count'] }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('notifications.preferences.update') }}">
                @csrf @method('PUT')

                {{-- NUMÉRO WHATSAPP --}}
                <div class="bg-emerald-50 p-8 rounded-[3rem] border border-emerald-200 mb-6">
                    <h3 class="text-[10px] font-black text-emerald-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-brands fa-whatsapp"></i> {{ __("Numéro WhatsApp") }}
                    </h3>

                    @php
                        $whatsappDriver = (string) setting('whatsapp.driver', 'log');
                        $adminPhone = (string) setting('whatsapp.admin_phone', '');
                        $myPhone = Auth::user()->whatsapp_phone;
                    @endphp

                    @if($whatsappDriver === 'log')
                        <div class="mb-4 p-4 bg-amber-100 text-amber-700 rounded-2xl text-[9px] font-black uppercase tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            {{ __("Aucun provider WhatsApp actif (mode journal). Configurez-en un dans Paramètres > WhatsApp pour recevoir de vrais messages.") }}
                            @can('admin.S')
                                <a href="{{ route('settings.index', ['group' => 'whatsapp']) }}" class="underline ml-auto no-underline text-amber-800">{{ __("Configurer") }} →</a>
                            @endcan
                        </div>
                    @endif

                    <div class="flex gap-4 items-end">
                        <div class="flex-1 space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Numéro avec indicatif") }}</label>
                            <input type="text" name="whatsapp_phone" value="{{ old('whatsapp_phone', $myPhone ?: $adminPhone) }}"
                                placeholder="+224 620 00 00 00"
                                class="w-full bg-white border-none rounded-2xl p-4 text-lg font-black shadow-sm outline-none focus:ring-4 focus:ring-emerald-500/10">
                        </div>
                        <a href="{{ route('notifications.test') }}"
                           onclick="event.preventDefault(); document.getElementById('test-form').submit();"
                           class="bg-emerald-500 text-white px-6 py-4 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-lg no-underline flex items-center gap-2 shrink-0">
                            <i class="fa-solid fa-paper-plane"></i> {{ __("Tester") }}
                        </a>
                    </div>
                    <p class="text-[8px] text-emerald-600 mt-3 italic">
                        {{ __("Le numéro doit être enregistré sur WhatsApp. Format : +224XXXXXXXXX") }}
                        @if(! $myPhone && $adminPhone)
                            — {{ __("Pré-rempli depuis Paramètres > WhatsApp (numéro de secours). Enregistrez pour confirmer.") }}
                        @endif
                    </p>
                    <p class="text-[8px] text-slate-400 mt-2 italic">
                        {{ __("Ce numéro personnel sert à VOS notifications. La configuration de l'API (driver, clé) se fait séparément dans Paramètres > WhatsApp.") }}
                    </p>
                </div>

                {{-- ACTIVATION GLOBALE --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-sm font-black text-slate-800 uppercase italic">{{ __("Notifications activées") }}</h3>
                            <p class="text-[9px] text-slate-400 mt-1">{{ __("Désactiver coupe TOUTES les notifications") }}</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ $prefs->is_active ? 'checked' : '' }}
                                class="sr-only peer">
                            <div class="w-14 h-7 bg-slate-200 peer-focus:ring-4 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-emerald-500"></div>
                        </label>
                    </div>
                </div>

                {{-- TYPES DE NOTIFICATIONS --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-bell text-amber-500"></i> {{ __("Types d'alertes") }}
                    </h3>

                    <div class="space-y-4">
                        @php
                            $notifTypes = [
                                ['name' => 'daily_summary', 'label' => __("Résumé quotidien (7h)"), 'desc' => __("Mortalité nuit, stocks, CA veille, tâches du jour"), 'icon' => 'fa-sun', 'color' => 'amber'],
                                ['name' => 'alert_mortality', 'label' => __("Alertes mortalité"), 'desc' => __("Pic de mortalité au-delà du seuil normal"), 'icon' => 'fa-skull', 'color' => 'red'],
                                ['name' => 'alert_stock', 'label' => __("Alertes stock"), 'desc' => __("Rupture ou stock sous le seuil d'alerte"), 'icon' => 'fa-boxes-stacked', 'color' => 'orange'],
                                ['name' => 'alert_energy', 'label' => __("Alertes eau & énergie"), 'desc' => __("Carburant bas, citerne basse, maintenance groupe"), 'icon' => 'fa-bolt', 'color' => 'cyan'],
                                ['name' => 'alert_sales', 'label' => __("Notifications ventes"), 'desc' => __("Nouvelle vente validée, paiement reçu"), 'icon' => 'fa-cash-register', 'color' => 'teal'],
                                ['name' => 'alert_fraud', 'label' => __("Alertes anti-fraude"), 'desc' => __("Écart détecté entre expédition et réception"), 'icon' => 'fa-shield-halved', 'color' => 'purple'],
                            ];
                        @endphp

                        @foreach($notifTypes as $nt)
                        <div class="flex justify-between items-center p-4 bg-slate-50 rounded-2xl">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-{{ $nt['color'] }}-100 rounded-xl flex items-center justify-center">
                                    <i class="fa-solid {{ $nt['icon'] }} text-{{ $nt['color'] }}-500"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-black text-slate-800 uppercase">{{ $nt['label'] }}</p>
                                    <p class="text-[8px] text-slate-400 mt-0.5">{{ $nt['desc'] }}</p>
                                </div>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="hidden" name="{{ $nt['name'] }}" value="0">
                                <input type="checkbox" name="{{ $nt['name'] }}" value="1" {{ $prefs->{$nt['name']} ? 'checked' : '' }}
                                    class="sr-only peer">
                                <div class="w-11 h-6 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                            </label>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- CANAUX --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-6 flex items-center gap-2">
                        <i class="fa-solid fa-tower-broadcast text-blue-500"></i> {{ __("Canaux de diffusion") }}
                    </h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <label class="p-4 bg-slate-50 rounded-2xl cursor-pointer text-center group">
                            <input type="hidden" name="channel_whatsapp" value="0">
                            <input type="checkbox" name="channel_whatsapp" value="1" {{ $prefs->channel_whatsapp ? 'checked' : '' }} class="sr-only peer">
                            <div class="peer-checked:bg-emerald-500 peer-checked:text-white bg-slate-200 text-slate-400 w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-2 transition-all">
                                <i class="fa-brands fa-whatsapp text-xl"></i>
                            </div>
                            <p class="text-[9px] font-black uppercase peer-checked:text-emerald-600 text-slate-400">WhatsApp</p>
                        </label>
                        <label class="p-4 bg-slate-50 rounded-2xl text-center opacity-50">
                            <div class="bg-slate-200 text-slate-400 w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-2">
                                <i class="fa-solid fa-bell text-xl"></i>
                            </div>
                            <p class="text-[9px] font-black uppercase text-slate-400">In-App</p>
                            <p class="text-[7px] text-slate-300">{{ __("Toujours actif") }}</p>
                        </label>
                        <label class="p-4 bg-slate-50 rounded-2xl cursor-pointer text-center group">
                            <input type="hidden" name="channel_email" value="0">
                            <input type="checkbox" name="channel_email" value="1" {{ $prefs->channel_email ? 'checked' : '' }} class="sr-only peer">
                            <div class="peer-checked:bg-amber-500 peer-checked:text-white bg-slate-200 text-slate-400 w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-2 transition-all">
                                <i class="fa-solid fa-envelope text-xl"></i>
                            </div>
                            <p class="text-[9px] font-black uppercase peer-checked:text-amber-600 text-slate-400">E-mail</p>
                        </label>
                        <label class="p-4 bg-slate-50 rounded-2xl cursor-pointer text-center group">
                            <input type="hidden" name="channel_sms" value="0">
                            <input type="checkbox" name="channel_sms" value="1" {{ $prefs->channel_sms ? 'checked' : '' }} class="sr-only peer">
                            <div class="peer-checked:bg-blue-500 peer-checked:text-white bg-slate-200 text-slate-400 w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-2 transition-all">
                                <i class="fa-solid fa-comment-sms text-xl"></i>
                            </div>
                            <p class="text-[9px] font-black uppercase peer-checked:text-blue-600 text-slate-400">SMS</p>
                        </label>
                    </div>
                </div>

                {{-- HEURES SILENCIEUSES --}}
                <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm mb-6">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-moon text-indigo-500"></i> {{ __("Heures silencieuses") }}
                    </h3>
                    <p class="text-[9px] text-slate-400 mb-4">{{ __("Pas de WhatsApp entre ces heures (sauf alertes critiques).") }}</p>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Début silence") }}</label>
                            <input type="time" name="quiet_start" value="{{ $prefs->quiet_start ?? '22:00' }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest ml-2">{{ __("Fin silence") }}</label>
                            <input type="time" name="quiet_end" value="{{ $prefs->quiet_end ?? '06:00' }}"
                                class="w-full bg-slate-50 border-none rounded-2xl p-4 text-sm font-black shadow-inner outline-none">
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-emerald-500 text-white py-6 rounded-[2rem] font-black text-xs uppercase tracking-[0.3em] hover:bg-emerald-600 transition-all shadow-2xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-2"></i> {{ __("Enregistrer les Préférences") }}
                </button>
            </form>

            {{-- Formulaire test caché --}}
            <form id="test-form" method="POST" action="{{ route('notifications.test') }}" class="hidden">@csrf</form>

            {{-- HISTORIQUE RÉCENT --}}
            @if($recentLogs->count() > 0)
            <div class="mt-10 bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="px-8 py-5 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-[10px] font-black uppercase text-slate-400 tracking-widest">{{ __("Dernières notifications") }}</h3>
                    <div class="flex items-center gap-4">
                        @can('admin.S')
                            <a href="{{ route('notifications.templates') }}" class="text-[8px] font-black text-emerald-600 uppercase tracking-widest no-underline hover:text-emerald-800">
                                <i class="fa-solid fa-comment-dots mr-1"></i>{{ __("Modèles de messages") }}
                            </a>
                            <a href="{{ route('notifications.audit') }}" class="text-[8px] font-black text-slate-600 uppercase tracking-widest no-underline hover:text-slate-900">
                                <i class="fa-solid fa-clipboard-list mr-1"></i>{{ __("Journal d'audit") }}
                            </a>
                            <a href="{{ route('backups.index') }}" class="text-[8px] font-black text-slate-600 uppercase tracking-widest no-underline hover:text-slate-900">
                                <i class="fa-solid fa-database mr-1"></i>{{ __("Sauvegardes") }}
                            </a>
                        @endcan
                        @can('notifications.S')
                            <a href="{{ route('notifications.logs') }}" class="text-[8px] font-black text-blue-500 uppercase tracking-widest no-underline hover:text-blue-700">
                                {{ __("Historique complet") }} →
                            </a>
                        @endcan
                    </div>
                </div>
                <div class="divide-y divide-slate-50">
                    @foreach($recentLogs as $log)
                    <div class="px-6 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span @class([
                                'w-2 h-2 rounded-full shrink-0',
                                'bg-emerald-500' => $log->status === 'sent',
                                'bg-red-500' => $log->status === 'failed',
                                'bg-amber-500' => $log->status === 'queued',
                            ])></span>
                            <div>
                                <p class="text-[10px] font-black text-slate-700">{{ $log->title }}</p>
                                <p class="text-[8px] text-slate-400">{{ $log->created_at->diffForHumans() }} — {{ $log->channel }}</p>
                            </div>
                        </div>
                        <span @class([
                            'text-[8px] font-black uppercase px-2 py-1 rounded-full',
                            'bg-emerald-50 text-emerald-600' => $log->status === 'sent',
                            'bg-red-50 text-red-600' => $log->status === 'failed',
                        ])>{{ $log->status }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
