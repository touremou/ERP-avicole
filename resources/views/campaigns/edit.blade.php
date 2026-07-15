<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Modifier') . ' — ' . $campaign->name" icon="fa-calendar-week" accent="emerald" :back="route('campaigns.show', $campaign)" />
    </x-slot>

    <div class="py-12 italic font-bold text-slate-700 text-left">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @if ($errors->any())
                <div class="bg-red-600 text-white p-6 rounded-[2rem] mb-8 shadow-xl">
                    <ul class="text-[10px] list-disc ml-8 uppercase font-black tracking-tight">
                        @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('campaigns.update', $campaign) }}" method="POST">
                @csrf
                @method('PUT')
                @include('campaigns.partials.form', ['campaign' => $campaign, 'nextEidDates' => $nextEidDates])
            </form>
        </div>
    </div>
</x-app-layout>
