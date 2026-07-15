{{-- Bandeau d'alerte en PÉRIODE DE GRÂCE : abonnement échu mais encore
     utilisable quelques jours. Visible sur toutes les pages tant que non
     renouvelé. N'apparaît que si le système de licence est armé. --}}
@php
    $licenseSvc = app(\App\Services\LicenseService::class);
@endphp
@if($licenseSvc->isEnabled() && $licenseSvc->status() === \App\Services\LicenseService::STATUS_GRACE)
    @php $lic = $licenseSvc->current(); @endphp
    <div class="bg-amber-500 text-white" x-data="{ show: true }" x-show="show">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 text-sm font-semibold not-italic">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>
                    {{ __("Abonnement échu") }}@if($lic) — {{ __("expiré le") }} {{ $lic->expires_at->translatedFormat('d M Y') }}@endif.
                    {{ __("Renouvelez-le pour éviter toute interruption.") }}
                </span>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('license.edit') }}" class="px-3 py-1.5 bg-white text-amber-700 rounded-lg text-xs font-black no-underline hover:bg-amber-50">
                    {{ __("Renouveler") }}
                </a>
                <button type="button" @click="show = false" class="text-white/80 hover:text-white border-none bg-transparent cursor-pointer">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
    </div>
@endif
