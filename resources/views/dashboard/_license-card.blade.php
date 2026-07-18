{{-- Carte « Durée de validité » de l'abonnement — affichée uniquement lorsque
     le système de licence est armé (clé publique posée + enforcement). --}}
@php
    $licenseSvc = app(\App\Services\LicenseService::class);
@endphp
@if($licenseSvc->isEnabled())
    @php
        $lic = $licenseSvc->current();
        $st  = $licenseSvc->status();
        $pct = $lic ? min(100, (int) round($lic->elapsed_days / max(1, $lic->total_days) * 100)) : 100;
        $barColor = match ($st) {
            \App\Services\LicenseService::STATUS_ACTIVE => $pct >= 90 ? 'bg-amber-400' : 'bg-emerald-400',
            \App\Services\LicenseService::STATUS_GRACE  => 'bg-amber-500',
            default                                     => 'bg-rose-500',
        };
        $badge = match ($st) {
            \App\Services\LicenseService::STATUS_ACTIVE => ['Actif', 'bg-emerald-100 text-emerald-600', '✅'],
            \App\Services\LicenseService::STATUS_GRACE  => ['Échéance proche', 'bg-amber-100 text-amber-700', '⚠️'],
            default                                     => ['Terminé', 'bg-rose-100 text-rose-600', '⛔'],
        };
    @endphp
    <div class="mb-8">
        <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 max-w-md not-italic">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-black text-slate-800">{{ __('Durée de validité') }}</h3>
                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider {{ $badge[1] }}"><span aria-hidden="true">{{ $badge[2] }}</span> {{ __($badge[0]) }}</span>
            </div>

            @if($lic)
                <p class="text-sm text-slate-400 mb-2">{{ __('Évolution contrat') }} : {{ $lic->elapsed_days }}/{{ $lic->total_days }} {{ __('Jours') }}</p>
                <div class="h-3 w-full rounded-full bg-slate-100 overflow-hidden mb-5">
                    <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                </div>

                <div class="space-y-2 text-sm text-slate-700">
                    <p>{{ __('Client') }}: <a href="{{ route('license.edit') }}" class="text-sky-500 font-semibold no-underline hover:underline">{{ $lic->client_name ?: $lic->identifiant }}</a></p>
                    <p>{{ __('Date fin contrat') }}: <span class="font-semibold {{ $st === 'active' ? 'text-emerald-500' : 'text-rose-500' }}">{{ $lic->expires_at->translatedFormat('d M Y') }}</span></p>
                    <p>{{ __('SMS restant') }}: <span class="font-semibold {{ $lic->sms_remaining > 0 ? 'text-rose-500' : 'text-rose-600' }}">{{ number_format($lic->sms_remaining, 0, ',', ' ') }}</span></p>
                </div>
            @else
                <p class="text-sm text-slate-500 mb-4">{{ __("Aucun abonnement activé sur cette instance.") }}</p>
                <a href="{{ route('license.edit') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-xl text-xs font-bold no-underline hover:bg-purple-700">
                    <i class="fa-solid fa-key"></i> {{ __('Activer un abonnement') }}
                </a>
            @endif
        </div>
    </div>
@endif
