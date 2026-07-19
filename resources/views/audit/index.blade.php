<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Journal d\'audit')" :subtitle="__('Qui a modifié quoi, quand')" icon="fa-clipboard-list" accent="slate" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 text-left">

            @php
                $eventLabels = [
                    'created'   => ['Création',     'bg-emerald-50 text-emerald-600'],
                    'updated'   => ['Modification', 'bg-blue-50 text-blue-600'],
                    'deleted'   => ['Suppression',  'bg-rose-50 text-rose-600'],
                    'claimed'   => ['Tâche prise',  'bg-indigo-50 text-indigo-600'],
                    'released'  => ['Tâche libérée', 'bg-amber-50 text-amber-600'],
                    'completed' => ['Tâche terminée', 'bg-emerald-50 text-emerald-600'],
                ];
            @endphp

            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                            <th class="px-6 py-4 text-left">{{ __('Date') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('Auteur') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('Action') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('Objet') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('Changements') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 text-[11px] font-bold">
                        @forelse($activities as $activity)
                            @php [$label, $badge] = $eventLabels[$activity->event] ?? [ucfirst((string) $activity->event), 'bg-slate-100 text-slate-600']; @endphp
                            <tr class="hover:bg-slate-50/50 align-top">
                                <td class="px-6 py-4 text-slate-500 whitespace-nowrap">{{ $activity->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-6 py-4 font-black text-slate-800 uppercase">{{ $activity->causer?->name ?? __('Système') }}</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 rounded-lg text-[8px] font-black uppercase {{ $badge }}">{{ $label }}</span>
                                </td>
                                <td class="px-6 py-4 font-black text-slate-700">{{ class_basename($activity->subject_type) }} #{{ $activity->subject_id }}</td>
                                <td class="px-6 py-4 text-slate-500">
                                    @php $attrs = $activity->properties['attributes'] ?? []; $old = $activity->properties['old'] ?? []; @endphp
                                    @if(empty($attrs))
                                        <span class="text-slate-300">—</span>
                                    @else
                                        <div class="space-y-0.5">
                                            @foreach($attrs as $k => $v)
                                                <div class="text-[10px]">
                                                    <span class="font-black text-slate-600">{{ $k }}</span> :
                                                    @if(array_key_exists($k, $old))
                                                        <span class="text-rose-400 line-through">{{ \Illuminate\Support\Str::limit((string) $old[$k], 30) }}</span>
                                                        <i class="fa-solid fa-arrow-right text-[7px] mx-1 text-slate-300"></i>
                                                    @endif
                                                    <span class="text-emerald-600 font-black">{{ \Illuminate\Support\Str::limit((string) $v, 30) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-slate-300 font-black uppercase text-[10px] tracking-widest italic">{{ __('Aucune activité enregistrée') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-6">{{ $activities->links() }}</div>
        </div>
    </div>
</x-app-layout>
