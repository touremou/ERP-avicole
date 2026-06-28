<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 text-left">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-slate-900 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-database text-lg"></i></div>
                <div>
                    <h2 class="font-black text-xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __('Sauvegardes') }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __('Base de données + fichiers · automatique chaque nuit') }}</p>
                </div>
            </div>
            <form action="{{ route('backups.run') }}" method="POST">
                @csrf
                <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-[9px] uppercase tracking-widest hover:bg-emerald-600 transition-all shadow-xl italic border-none cursor-pointer">
                    <i class="fa-solid fa-play mr-2"></i> {{ __('Sauvegarder maintenant') }}
                </button>
            </form>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 text-left">

            <x-flash />

            <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 mb-6 text-[11px] text-blue-800 font-bold italic">
                <i class="fa-solid fa-circle-info mr-1"></i>
                {{ __('Conservez une copie des sauvegardes HORS de ce serveur (téléchargement régulier ou réplication distante).') }}
            </div>

            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-4 text-left">{{ __('Sauvegarde') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('Date') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Taille') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 text-[11px] font-bold">
                        @forelse($backups as $b)
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-4 font-black text-slate-800">{{ $b['name'] }}</td>
                                <td class="px-6 py-4 text-slate-500">{{ \Illuminate\Support\Carbon::createFromTimestamp($b['date'])->format('d/m/Y H:i') }}</td>
                                <td class="px-6 py-4 text-right text-slate-500">{{ number_format($b['size'] / 1048576, 1) }} {{ __('Mo') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('backups.download', $b['name']) }}" class="text-[9px] font-black text-blue-600 uppercase tracking-widest no-underline hover:text-blue-800">
                                        <i class="fa-solid fa-download mr-1"></i>{{ __('Télécharger') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-12 text-center text-slate-300 font-black uppercase text-[10px] tracking-widest italic">{{ __('Aucune sauvegarde pour le moment') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
