<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Calendrier cultural')" :subtitle="__('Assolement annuel — du semis à la récolte')" icon="fa-calendar-days" accent="green" />
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-6">

            {{-- FILTRE ANNÉE + LÉGENDE --}}
            <div class="flex flex-wrap items-center justify-between gap-4">
                <form method="GET" class="flex items-center gap-3">
                    <span class="text-[9px] font-black text-slate-400 uppercase italic">{{ __("Année") }}</span>
                    <select name="year" onchange="this.form.submit()" class="bg-white border border-slate-100 rounded-2xl px-4 py-2 font-black text-slate-800 shadow-sm italic text-[11px] cursor-pointer">
                        @foreach($years as $y)<option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>@endforeach
                    </select>
                </form>
                <div class="flex items-center gap-4 text-[8px] font-black uppercase text-slate-400">
                    <span><span class="inline-block w-3 h-3 bg-green-200 rounded-sm align-middle"></span> {{ __("En culture") }}</span>
                    <span><span class="inline-block w-3 h-3 bg-green-600 rounded-sm align-middle"></span> {{ __("Semis") }}</span>
                    <span><span class="inline-block w-3 h-3 bg-amber-500 rounded-sm align-middle"></span> {{ __("Récolte") }}</span>
                </div>
            </div>

            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-[8px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                <th class="p-3 sticky left-0 bg-white">{{ __("Culture") }}</th>
                                @foreach(['J','F','M','A','M','J','J','A','S','O','N','D'] as $mLabel)
                                    <th class="p-2 text-center w-8">{{ $mLabel }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr class="border-b border-slate-50">
                                    <td class="p-3 sticky left-0 bg-white">
                                        <a href="{{ route('crop-cycles.show', $row['cycle']) }}" class="no-underline">
                                            <p class="text-[11px] font-black uppercase text-slate-800 italic leading-none">{{ $row['cycle']->crop_name }}</p>
                                            <p class="text-[8px] text-slate-400 uppercase mt-0.5">{{ $row['cycle']->plot?->name }}</p>
                                        </a>
                                    </td>
                                    @for($m = 1; $m <= 12; $m++)
                                        @php $cell = $row['months'][$m]; @endphp
                                        <td class="p-1 text-center">
                                            @if($cell['planting'])
                                                <div class="h-6 bg-green-600 rounded-sm flex items-center justify-center" title="Semis"><i class="fa-solid fa-seedling text-white text-[8px]"></i></div>
                                            @elseif($cell['harvest'])
                                                <div class="h-6 bg-amber-500 rounded-sm flex items-center justify-center" title="{{ __('Récolte') }}"><i class="fa-solid fa-wheat-awn text-white text-[8px]"></i></div>
                                            @elseif($cell['occupied'])
                                                <div class="h-6 bg-green-200 rounded-sm"></div>
                                            @else
                                                <div class="h-6"></div>
                                            @endif
                                        </td>
                                    @endfor
                                </tr>
                            @empty
                                <tr><td colspan="13" class="p-16 text-center text-slate-300 text-[10px] font-black uppercase italic">{{ __("Aucun cycle sur") }} {{ $year }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
