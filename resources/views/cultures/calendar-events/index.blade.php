<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-calendar-days text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Événements calendaires") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Production Végétale — tous les événements") }}</p>
                </div>
            </div>
            @can('cultures.C')
            <a href="{{ route('crop-calendar-events.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                <i class="fa-solid fa-plus"></i> {{ __("Nouvel événement") }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            <x-flash />

            <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm">
                @forelse($events as $event)
                    @php
                        $colorMap = [
                            'green'  => 'bg-green-100 text-green-700',
                            'blue'   => 'bg-blue-100 text-blue-700',
                            'amber'  => 'bg-amber-100 text-amber-700',
                            'red'    => 'bg-red-100 text-red-700',
                            'purple' => 'bg-purple-100 text-purple-700',
                            'slate'  => 'bg-slate-100 text-slate-700',
                        ];
                        $badgeClass = $colorMap[$event->color] ?? 'bg-green-100 text-green-700';
                    @endphp
                    <div class="flex items-center justify-between p-4 mb-2 bg-slate-50 rounded-[1.5rem] hover:bg-green-50 transition">
                        <div class="flex items-center gap-4">
                            <span class="px-3 py-1 rounded-xl text-[9px] font-black uppercase {{ $badgeClass }}">{{ $event->type_label }}</span>
                            <div>
                                <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $event->title }}</p>
                                <p class="text-[9px] text-slate-400 uppercase mt-1">
                                    {{ $event->event_date->format('d/m/Y') }}
                                    @if($event->end_date && $event->end_date->ne($event->event_date))
                                        → {{ $event->end_date->format('d/m/Y') }}
                                    @endif
                                    @if($event->cropCycle)
                                        · {{ $event->cropCycle->crop_name }}
                                    @endif
                                </p>
                            </div>
                        </div>
                        @can('cultures.M')
                        <a href="{{ route('crop-calendar-events.edit', $event) }}" class="text-[9px] font-black uppercase text-slate-400 hover:text-green-600 transition no-underline italic">
                            <i class="fa-solid fa-pen mr-1"></i> {{ __("Modifier") }}
                        </a>
                        @endcan
                    </div>
                @empty
                    <p class="text-center text-slate-300 text-[10px] font-black uppercase italic py-10">{{ __("Aucun événement") }}</p>
                @endforelse

                @if($events->hasPages())
                    <div class="mt-6">{{ $events->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
