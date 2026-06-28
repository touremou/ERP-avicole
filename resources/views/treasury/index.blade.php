<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-800 uppercase italic tracking-tighter leading-none">
                    💰 {{ __("Trésorerie") }}
                </h2>
                <p class="text-[10px] font-black text-emerald-500 uppercase tracking-widest mt-1 italic leading-none">
                    {{ __("Caisse · Mobile Money · Banque") }}
                </p>
            </div>
            <div class="text-right">
                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ __("Total disponible") }}</p>
                <p class="text-2xl font-black text-emerald-600 leading-none">{{ number_format($total, 0, ',', ' ') }} <span class="text-xs">{{ currency() }}</span></p>
            </div>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left space-y-8">

            <x-flash />

            {{-- COMPTES --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @forelse($accounts as $account)
                <a href="{{ route('treasury.show', $account) }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm hover:shadow-md hover:border-emerald-200 transition-all no-underline">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 bg-slate-900 rounded-2xl flex items-center justify-center text-white shadow-lg shrink-0">
                            <i class="fa-solid {{ $account->type_icon }}"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-black text-slate-800 uppercase truncate">{{ $account->name }}</p>
                            <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">{{ $account->type_label }}</p>
                        </div>
                    </div>
                    <p class="text-2xl font-black {{ $account->current_balance < 0 ? 'text-red-600' : 'text-slate-900' }} leading-none">
                        {{ number_format($account->current_balance, 0, ',', ' ') }} <span class="text-[10px] text-slate-400">{{ currency() }}</span>
                    </p>
                </a>
                @empty
                <p class="md:col-span-3 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest py-8 bg-white rounded-[2rem] border border-slate-100">
                    {{ __("Aucun compte. Créez votre caisse, votre compte Mobile Money et votre banque ci-dessous.") }}
                </p>
                @endforelse
            </div>

            @can('depenses.C')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- NOUVEAU COMPTE --}}
                <form method="POST" action="{{ route('treasury.account.store') }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm space-y-4">
                    @csrf
                    <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-widest"><i class="fa-solid fa-plus mr-1"></i> {{ __("Nouveau compte") }}</h3>
                    <input type="text" name="name" required placeholder="{{ __('Nom (ex. Caisse principale)') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none">
                    <select name="type" required class="w-full bg-slate-50 border-none rounded-2xl p-4 text-[10px] font-black uppercase shadow-inner outline-none appearance-none cursor-pointer">
                        @foreach($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="number" name="opening_balance" min="0" step="1" value="0" placeholder="{{ __('Solde d\'ouverture') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none text-right">
                    <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer">{{ __("Créer le compte") }}</button>
                </form>

                {{-- TRANSFERT --}}
                <form method="POST" action="{{ route('treasury.transfer') }}" class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-sm space-y-4">
                    @csrf
                    <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-widest"><i class="fa-solid fa-right-left mr-1"></i> {{ __("Transfert entre comptes") }}</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <select name="from_id" required class="bg-slate-50 border-none rounded-2xl p-3 text-[10px] font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("Depuis…") }}</option>
                            @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                        </select>
                        <select name="to_id" required class="bg-slate-50 border-none rounded-2xl p-3 text-[10px] font-black uppercase shadow-inner outline-none">
                            <option value="">{{ __("Vers…") }}</option>
                            @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                        </select>
                    </div>
                    <input type="number" name="amount" required min="1" step="1" placeholder="{{ __('Montant') }}" class="w-full bg-slate-50 border-none rounded-2xl p-4 text-xs font-black shadow-inner outline-none text-right">
                    <input type="date" name="date" value="{{ now()->toDateString() }}" max="{{ date('Y-m-d') }}" required class="w-full bg-slate-50 border-none rounded-2xl p-3 text-xs font-black shadow-inner outline-none">
                    <button type="submit" {{ $accounts->count() < 2 ? 'disabled' : '' }} class="w-full bg-slate-900 text-white py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 transition-all border-none cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">{{ __("Transférer") }}</button>
                    @if($accounts->count() < 2)<p class="text-[8px] text-slate-400 uppercase tracking-widest text-center">{{ __("Il faut au moins 2 comptes.") }}</p>@endif
                </form>
            </div>
            @endcan
        </div>
    </div>
</x-app-layout>
