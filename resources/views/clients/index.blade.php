<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="__('Fichier Clients')" :subtitle="__(':count clients enregistrés', ['count' => $stats['total_clients']])" icon="fa-users" accent="teal">
            <x-slot name="actions">
                <div class="bg-white px-6 py-4 rounded-[1.5rem] border border-slate-100 text-right shadow-sm">
                    <p class="text-[8px] font-black text-rose-400 uppercase italic mb-1">{{ __("Créances Totales") }}</p>
                    <p class="text-base font-black text-slate-900 leading-none">{{ number_format($stats['total_debt'], 0, ',', ' ') }} <small class="text-[9px] opacity-40">{{ setting('general.currency', 'GNF') }}</small></p>
                </div>
                @can('commerce.C')
                <a href="{{ route('clients.create') }}" class="bg-slate-900 text-white px-8 py-4 rounded-[2rem] font-black text-[10px] uppercase tracking-widest hover:bg-teal-600 transition-all shadow-2xl italic flex items-center gap-2 no-underline">
                    <i class="fa-solid fa-user-plus"></i> {{ __("Nouveau Client") }}
                </a>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 italic font-bold text-left">

            <x-flash />

            {{-- FILTRES --}}
            <form method="GET" class="mb-8 flex flex-wrap gap-3 items-center">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('RECHERCHER...') }}" class="bg-white border border-slate-100 rounded-2xl pl-10 pr-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm w-56 outline-none">
                </div>
                <select name="category" class="bg-white border border-slate-100 rounded-2xl px-4 py-3 text-[10px] font-black uppercase tracking-widest shadow-sm outline-none appearance-none">
                    <option value="">{{ __("Toutes catégories") }}</option>
                    <option value="grossiste" {{ request('category') === 'grossiste' ? 'selected' : '' }}>{{ __("Grossiste") }}</option>
                    <option value="detaillant" {{ request('category') === 'detaillant' ? 'selected' : '' }}>{{ __("Détaillant") }}</option>
                    <option value="hotel_restaurant" {{ request('category') === 'hotel_restaurant' ? 'selected' : '' }}>{{ __("Hôtel/Restaurant") }}</option>
                    <option value="revendeur" {{ request('category') === 'revendeur' ? 'selected' : '' }}>{{ __("Revendeur") }}</option>
                </select>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="with_debt" value="1" {{ request('with_debt') ? 'checked' : '' }} class="rounded border-slate-300">
                    <span class="text-[9px] font-black uppercase text-rose-500 tracking-widest">{{ __("Débiteurs uniquement") }}</span>
                </label>
                <button type="submit" class="bg-slate-900 text-white px-5 py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border-none cursor-pointer">{{ __("Filtrer") }}</button>
            </form>

            {{-- TABLEAU --}}
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-50 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 italic">
                                <th class="px-8 py-6 text-left">{{ __("Client") }}</th>
                                <th class="px-6 py-6 text-left">{{ __("Catégorie") }}</th>
                                <th class="px-6 py-6 text-left">{{ __("Téléphone") }}</th>
                                <th class="px-6 py-6 text-right">{{ __("Ventes") }}</th>
                                <th class="px-6 py-6 text-right">{{ __("Solde Dû") }}</th>
                                <th class="px-6 py-6 text-center">{{ __("Statut") }}</th>
                                <th class="px-8 py-6 text-right">{{ __("Actions") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($clients as $client)
                            <tr class="hover:bg-slate-50/50 transition-all group">
                                <td class="px-8 py-5">
                                    <a href="{{ route('clients.show', $client) }}" class="no-underline group-hover:text-teal-600 transition-colors">
                                        <p class="font-black text-slate-900 text-sm uppercase italic leading-none mb-1">{{ $client->name }}</p>
                                        <p class="text-[8px] text-slate-400 font-black uppercase tracking-widest">{{ $client->client_id }}</p>
                                    </a>
                                </td>
                                <td class="px-6 py-5">
                                    <span @class([
                                        'text-[8px] font-black uppercase px-3 py-1 rounded-full tracking-widest',
                                        'bg-blue-50 text-blue-600' => $client->category === 'grossiste',
                                        'bg-emerald-50 text-emerald-600' => $client->category === 'detaillant',
                                        'bg-purple-50 text-purple-600' => $client->category === 'hotel_restaurant',
                                        'bg-amber-50 text-amber-600' => $client->category === 'revendeur',
                                        'bg-slate-50 text-slate-500' => $client->category === 'autre',
                                    ])>{{ ucfirst(str_replace('_', '/', $client->category)) }}</span>
                                </td>
                                <td class="px-6 py-5 text-[10px] text-slate-600 font-black">{{ $client->phone ?? '—' }}</td>
                                <td class="px-6 py-5 text-right text-[10px] font-black text-slate-600">{{ $client->sales_count }}</td>
                                <td class="px-6 py-5 text-right">
                                    @if($client->balance > 0)
                                        <span class="text-sm font-black {{ $client->is_over_limit ? 'text-red-600' : 'text-amber-600' }}">
                                            {{ number_format($client->balance, 0, ',', ' ') }}
                                        </span>
                                    @else
                                        <span class="text-[10px] text-emerald-500 font-black">{{ __("Soldé") }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <span @class([
                                        'text-[8px] font-black uppercase px-3 py-1 rounded-full',
                                        'bg-emerald-50 text-emerald-600' => $client->status === 'actif',
                                        'bg-amber-50 text-amber-600' => $client->status === 'suspendu',
                                        'bg-red-50 text-red-600' => $client->status === 'blackliste',
                                    ])>{{ $client->status }}</span>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    @can('commerce.C')
                                    <a href="{{ route('sales.create', ['client_id' => $client->id]) }}" class="text-teal-500 hover:text-teal-700 mr-3 no-underline" title="{{ __('Nouvelle vente') }}">
                                        <i class="fa-solid fa-cart-plus"></i>
                                    </a>
                                    @endcan
                                    <a href="{{ route('clients.show', $client) }}" class="text-slate-400 hover:text-slate-700 no-underline" title="{{ __('Détails') }}">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-8 py-16 text-center">
                                    <i class="fa-solid fa-users-slash text-slate-200 text-3xl mb-4 block"></i>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-widest font-black">{{ __("Aucun client enregistré") }}</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">{{ $clients->withQueryString()->links() }}</div>
        </div>
    </div>
</x-app-layout>
