<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-green-600 rounded-2xl flex items-center justify-center text-white shadow-lg -rotate-3">
                    <i class="fa-solid fa-list-check text-lg"></i>
                </div>
                <div class="text-left">
                    <h2 class="font-black text-2xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ $protocol->name }}</h2>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __("Modifier l'itinéraire") }}</p>
                </div>
            </div>
            <a href="{{ route('crop-protocols.show', $protocol) }}" class="text-[10px] font-black uppercase text-slate-400 hover:text-slate-900 transition no-underline">
                <i class="fa-solid fa-xmark mr-2"></i> {{ __("Annuler") }}
            </a>
        </div>
    </x-slot>

    @php
        $seedItems = old('items', $protocol->items->map(fn($it) => [
            'day_number'        => $it->day_number,
            'stage'             => $it->stage,
            'action_name'       => $it->action_name,
            'type'              => $it->type,
            'product_suggested' => $it->product_suggested,
            'dose'              => $it->dose,
            'method'            => $it->method,
            'notes'             => $it->notes,
        ])->values()->all());
        if (empty($seedItems)) {
            $seedItems = [['day_number' => 0, 'stage' => '', 'action_name' => '', 'type' => 'semis', 'product_suggested' => '', 'dose' => '', 'method' => '', 'notes' => '']];
        }
    @endphp

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('crop-protocols.update', $protocol) }}" method="POST"
                  x-data="{ items: {{ Js::from($seedItems) }} }"
                  class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf @method('PUT')
                @include('cultures.protocols._form', ['protocol' => $protocol])

                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Enregistrer") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
