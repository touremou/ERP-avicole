<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Nouvel itinéraire technique')" :subtitle="__('Calendrier cultural de référence')" icon="fa-list-check" accent="green" :back="route('crop-protocols.index')" />
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-6 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('crop-protocols.store') }}" method="POST"
                  x-data="{ items: [{ day_number: 0, stage: '', action_name: '', type: 'semis', product_suggested: '', dose: '', method: '', notes: '' }] }"
                  class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-sm space-y-6">
                @csrf
                @include('cultures.protocols._form', ['protocol' => null])

                <div class="flex justify-end pt-4 border-t border-slate-50">
                    <button type="submit" class="bg-slate-900 text-white px-12 py-5 rounded-[2rem] font-black uppercase italic tracking-[0.2em] text-[11px] shadow-2xl hover:bg-green-600 transition-all">
                        <i class="fa-solid fa-check mr-2 text-green-400"></i> {{ __("Créer l'itinéraire") }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
