<x-app-layout>
    <x-slot name="header">
        <x-page-header
            :title="'🔔 ' . __('Centre d\'alertes')"
            :subtitle="__(':unread non lue(s) sur :total', ['unread' => $unreadCount, 'total' => $totalCount])"
            icon="fa-bell" accent="rose" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- La cloche n'affichait que les non lues, et cliquer les faisait
                 disparaître aussitôt : rien ne permettait de les retrouver. Cet
                 écran est l'historique, comme le mobile le fait déjà. --}}
            <div class="flex flex-wrap items-center gap-2 mb-5">
                @foreach(['toutes' => [__('Toutes'), $totalCount], 'non_lues' => [__('Non lues'), $unreadCount]] as $key => [$label, $count])
                    <a href="{{ route('notifications.index', ['vue' => $key]) }}"
                       @class([
                           'px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest no-underline transition-all',
                           'bg-slate-900 text-white shadow-lg' => $filter === $key,
                           'bg-white text-slate-500 border border-slate-200 hover:bg-slate-50' => $filter !== $key,
                       ])>
                        {{ $label }} <span class="opacity-60">({{ $count }})</span>
                    </a>
                @endforeach

                @if($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}" class="ml-auto">
                        @csrf
                        <button type="submit" class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest bg-emerald-50 text-emerald-600 border border-emerald-200 hover:bg-emerald-100 transition-all cursor-pointer">
                            <i class="fa-solid fa-check-double mr-1"></i> {{ __("Tout marquer comme lu") }}
                        </button>
                    </form>
                @endif
            </div>

            <div class="space-y-3">
                @forelse($notifications as $notification)
                    @php
                        $severity = $notification->data['severity'] ?? 'normal';
                        $url = $notification->data['url'] ?? null;
                        $isUnread = $notification->read_at === null;
                    @endphp

                    <div @class([
                        'bg-white rounded-[2rem] border shadow-sm p-5 flex items-start gap-4 transition-all',
                        'border-slate-100' => ! $isUnread,
                        'border-rose-200 shadow-rose-100/50' => $isUnread && $severity === 'critique',
                        'border-amber-200' => $isUnread && $severity === 'attention',
                        'border-blue-200' => $isUnread && ! in_array($severity, ['critique', 'attention']),
                        'opacity-60' => ! $isUnread,
                    ])>
                        <span @class([
                            'w-10 h-10 rounded-full flex items-center justify-center text-base shrink-0',
                            'bg-rose-100'  => $severity === 'critique',
                            'bg-amber-100' => $severity === 'attention',
                            'bg-blue-100'  => ! in_array($severity, ['critique', 'attention']),
                        ])>{{ notif_icon($notification->data['type'] ?? null, $severity) }}</span>

                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-baseline gap-2 mb-1">
                                <p class="text-[11px] font-black text-slate-800 uppercase italic leading-none">
                                    {{ $notification->data['title'] ?? __("Alerte") }}
                                </p>
                                @if($isUnread)
                                    <span class="px-2 py-0.5 rounded-full bg-rose-600 text-white text-[7px] font-black uppercase tracking-widest">{{ __("Nouveau") }}</span>
                                @endif
                            </div>

                            <p class="text-[10px] text-slate-500 font-bold leading-snug normal-case">
                                {{ $notification->data['message'] ?? '' }}
                            </p>

                            {{-- La DATE : sans elle, deux alertes du même contrôle à
                                 deux jours d'intervalle se lisaient comme un doublon. --}}
                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-2">
                                {{ $notification->created_at->translatedFormat('d/m/Y à H:i') }}
                                <span class="opacity-60">· {{ $notification->created_at->diffForHumans() }}</span>
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 shrink-0">
                            @if($url)
                                <a href="{{ route('notifications.read', $notification->id) }}"
                                   class="px-3 py-2 rounded-xl bg-slate-900 text-white text-[8px] font-black uppercase tracking-widest no-underline hover:bg-blue-600 transition-all text-center">
                                    {{ __("Ouvrir") }}
                                </a>
                            @elseif($isUnread)
                                <a href="{{ route('notifications.read', $notification->id) }}"
                                   class="px-3 py-2 rounded-xl bg-slate-100 text-slate-500 text-[8px] font-black uppercase tracking-widest no-underline hover:bg-slate-200 transition-all text-center">
                                    {{ __("Marquer lu") }}
                                </a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="bg-white p-16 rounded-[3rem] border border-slate-100 shadow-sm text-center">
                        <i class="fa-solid fa-check-circle text-emerald-500 text-3xl mb-4"></i>
                        <p class="text-[11px] font-black text-slate-400 uppercase italic tracking-widest">
                            {{ $filter === 'non_lues' ? __("Aucune alerte non lue") : __("Aucune alerte reçue") }}
                        </p>
                    </div>
                @endforelse
            </div>

            @if($notifications->hasPages())
                <div class="mt-6">{{ $notifications->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
