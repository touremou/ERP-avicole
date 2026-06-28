<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4 text-left">
            <div class="w-12 h-12 bg-slate-900 rounded-xl flex items-center justify-center text-white shadow-lg"><i class="fa-solid fa-table-cells-large text-lg"></i></div>
            <div>
                <h2 class="font-black text-xl text-slate-800 uppercase italic tracking-tighter leading-none">{{ __('Mon tableau de bord') }}</h2>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1 italic">{{ __('Choisir les blocs affichés') }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 text-left">

            <x-flash />

            <form action="{{ route('dashboard.config.update') }}" method="POST" class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6">
                @csrf @method('PUT')

                <p class="text-[11px] text-slate-500 font-bold italic mb-5">
                    <i class="fa-solid fa-circle-info mr-1 text-blue-500"></i>
                    {{ __('Décochez un bloc pour le masquer de votre tableau de bord. Ce réglage est personnel et n\'affecte pas les autres utilisateurs.') }}
                </p>

                <div class="space-y-2">
                    @foreach($blocks as $key => $label)
                    <label class="flex items-center justify-between gap-4 p-4 rounded-2xl border border-slate-100 hover:bg-slate-50 transition cursor-pointer">
                        <span class="text-[12px] font-black text-slate-700 italic">{{ $label }}</span>
                        <input type="checkbox" name="visible[]" value="{{ $key }}"
                               {{ in_array($key, $hidden, true) ? '' : 'checked' }}
                               class="w-5 h-5 rounded accent-emerald-600">
                    </label>
                    @endforeach
                </div>

                <div class="flex items-center justify-between mt-6">
                    <a href="{{ route('dashboard') }}" class="text-[10px] font-black text-slate-400 hover:text-slate-700 uppercase tracking-widest no-underline">
                        <i class="fa-solid fa-arrow-left mr-1"></i> {{ __('Retour') }}
                    </a>
                    <button type="submit" class="bg-slate-900 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-700 transition-all">
                        <i class="fa-solid fa-floppy-disk mr-1"></i> {{ __('Enregistrer') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
