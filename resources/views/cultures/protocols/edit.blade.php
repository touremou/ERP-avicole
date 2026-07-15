<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$protocol->name" :subtitle="__('Modifier l\'itinéraire')" icon="fa-list-check" accent="green" :back="route('crop-protocols.show', $protocol)" />
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
