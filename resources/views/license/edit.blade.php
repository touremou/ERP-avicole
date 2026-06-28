<x-app-layout>
    <x-slot name="header">
        {{-- license.edit se termine par .edit → page « feuille » : le layout
             n'injecte pas de <x-hub-back>, pas de double flèche. --}}
        <x-page-header :title="__('Prolongez la date de validité du projet')" :subtitle="__('Activation / renouvellement de l\'abonnement')" icon="fa-key" accent="purple" />
    </x-slot>

    <div class="max-w-5xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">

                {{-- ÉTAT DE L'ABONNEMENT --}}
                @php
                    $isBlocked = in_array($status, [\App\Services\LicenseService::STATUS_EXPIRED, \App\Services\LicenseService::STATUS_NONE], true);
                    $isGrace   = $status === \App\Services\LicenseService::STATUS_GRACE;
                @endphp

                @if ($isBlocked)
                    <div class="flex items-stretch gap-0 rounded-2xl overflow-hidden shadow-sm">
                        <div class="flex items-center justify-center w-20 bg-rose-500 text-white text-2xl">
                            <i class="fa-solid fa-thumbs-down"></i>
                        </div>
                        <div class="flex-1 bg-rose-400 text-white px-5 py-4 text-sm leading-relaxed">
                            {{ __("Désolé, votre abonnement a expiré. Veuillez le renouveler si vous avez le code, ou contacter le fournisseur :") }}
                            <br>
                            <strong>{{ $vendor['name'] }}@if($vendor['address']) — {{ $vendor['address'] }}@endif @if($vendor['phone']) — Tél : {{ $vendor['phone'] }}@endif</strong>
                        </div>
                    </div>
                @elseif ($isGrace)
                    <div class="rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 px-5 py-4 text-sm">
                        <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                        {{ __("Votre abonnement est échu mais reste utilisable encore quelques jours (période de grâce). Renouvelez-le dès maintenant pour éviter toute interruption.") }}
                    </div>
                @elseif ($license)
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-4 text-sm flex flex-wrap gap-x-8 gap-y-1">
                        <span><i class="fa-solid fa-circle-check mr-2"></i>{{ __("Abonnement actif") }}</span>
                        <span>{{ __("Client") }} : <strong>{{ $license->client_name ?: $license->identifiant }}</strong></span>
                        <span>{{ __("Échéance") }} : <strong>{{ $license->expires_at->translatedFormat('d M Y') }}</strong></span>
                        <span>{{ __("Jours restants") }} : <strong>{{ $license->days_remaining }}</strong></span>
                        <span>{{ __("Plan") }} : <strong>{{ ucfirst($license->plan) }}</strong></span>
                    </div>
                @endif

                @if (session('success'))
                    <div class="rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-3 text-sm">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 px-5 py-3 text-sm">{{ session('error') }}</div>
                @endif

                {{-- FORMULAIRE D'ACTIVATION --}}
                <form method="POST" action="{{ route('license.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">{{ __("Identifiant") }} <span class="text-rose-500">**</span></label>
                            <div class="flex items-stretch rounded-xl border border-slate-200 overflow-hidden focus-within:ring-2 focus-within:ring-purple-300">
                                <span class="flex items-center justify-center w-12 bg-slate-50 text-slate-400"><i class="fa-solid fa-user"></i></span>
                                <input type="text" name="identifiant" value="{{ old('identifiant', $license?->identifiant) }}"
                                       class="flex-1 px-4 py-3 border-0 focus:ring-0 text-sm" placeholder="{{ __('Votre identifiant client') }}" required>
                            </div>
                            @error('identifiant')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">{{ __("Code de validité") }} <span class="text-rose-500">**</span></label>
                            <div class="flex items-stretch rounded-xl border border-slate-200 overflow-hidden focus-within:ring-2 focus-within:ring-purple-300">
                                <span class="flex items-center justify-center w-12 bg-slate-50 text-slate-400"><i class="fa-solid fa-lock"></i></span>
                                <input type="text" name="code" value="{{ old('code') }}"
                                       class="flex-1 px-4 py-3 border-0 focus:ring-0 text-sm font-mono" placeholder="{{ __('Collez ici le code fourni') }}" required>
                            </div>
                            @error('code')<p class="text-xs text-rose-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 bg-purple-600 text-white rounded-xl text-sm font-bold hover:bg-purple-700 transition-all border-none cursor-pointer shadow-lg">
                            <i class="fa-solid fa-arrows-rotate"></i> {{ __("Modifier") }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
