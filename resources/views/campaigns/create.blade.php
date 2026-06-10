<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <a href="{{ route('campaigns.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-800 rounded-xl transition-all shadow-sm no-underline">
                <i class="fas fa-chevron-left text-xs"></i>
                <span class="text-[10px] font-black uppercase italic tracking-widest leading-none">Retour</span>
            </a>
            <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">🐑 Nouvelle campagne</h2>
        </div>
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

            <form action="{{ route('campaigns.store') }}" method="POST">
                @csrf
                @include('campaigns.partials.form', ['campaign' => null, 'nextEidDates' => $nextEidDates])
            </form>
        </div>
    </div>
</x-app-layout>
