<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-industry text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __("Transformation Végétale") }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Agro-transformation des récoltes") }}</p>
                </div>
            </div>
            @can('cultures.C')
            <a href="{{ route('crop-transformations.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                <i class="fa-solid fa-plus"></i> {{ __("Nouvelle Transformation") }}
            </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            <x-flash />

            {{-- INDICATEURS --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Opérations (30 j)") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ $stats['count_30d'] }}</p>
                </div>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <p class="text-[8px] font-black text-green-500 uppercase tracking-widest italic mb-2">{{ __("Produit fini (30 j)") }}</p>
                    <p class="text-3xl font-black text-slate-900 leading-none">{{ number_format($stats['output_30d'], 0, ',', ' ') }} <small class="text-[10px] opacity-40">kg</small></p>
                </div>
                <div class="bg-slate-900 text-white p-6 rounded-[2rem] shadow-lg">
                    <p class="text-[8px] font-black text-green-400 uppercase tracking-widest italic mb-2">{{ __("Rendement moyen") }}</p>
                    <p class="text-3xl font-black leading-none">{{ number_format($stats['avg_yield'], 1, ',', ' ') }} <small class="text-[10px] opacity-40">%</small></p>
                </div>
            </div>

            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-50">
                        <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest">
                            <th class="p-5">{{ __("Lot") }}</th>
                            <th class="p-5">{{ __("Transformation") }}</th>
                            <th class="p-5 text-center">{{ __("Date") }}</th>
                            <th class="p-5 text-right">{{ __("Entrée") }}</th>
                            <th class="p-5 text-right">{{ __("Sortie") }}</th>
                            <th class="p-5 text-center">{{ __("Rendement") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($transformations as $t)
                            <tr class="hover:bg-slate-50/50 transition cursor-pointer" onclick="window.location='{{ route('crop-transformations.show', $t) }}'">
                                <td class="p-5 text-[10px] font-black text-slate-400 uppercase">{{ $t->batch_number }}</td>
                                <td class="p-5">
                                    <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $t->input_product }} → {{ $t->output_product }}</p>
                                    <p class="text-[8px] text-slate-400 uppercase mt-1">{{ $t->type_label }}</p>
                                </td>
                                <td class="p-5 text-center text-[10px] font-bold text-slate-500">{{ $t->production_date?->format('d/m/Y') }}</td>
                                <td class="p-5 text-right text-[10px] font-black text-slate-700">{{ number_format($t->input_quantity, 0, ',', ' ') }} {{ $t->input_unit }}</td>
                                <td class="p-5 text-right text-[10px] font-black text-green-600">{{ number_format($t->output_quantity, 0, ',', ' ') }} {{ $t->output_unit }}</td>
                                <td class="p-5 text-center"><span class="px-3 py-1 rounded-full text-[8px] font-black uppercase {{ $t->yield_percent >= 50 ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600' }}">{{ number_format($t->yield_percent, 1, ',', ' ') }}%</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-16 text-center text-slate-300 text-[10px] font-black uppercase italic">{{ __("Aucune transformation enregistrée") }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $transformations->links() }}</div>
        </div>
    </div>
</x-app-layout>
