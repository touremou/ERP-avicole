{{-- RAPPEL D'ÉCHÉANCE DE L'ABONNEMENT — sur le tableau de bord.

     La carte « Durée de validité » occupait le haut du tableau de bord, pour
     TOUS les utilisateurs et en permanence : de la place prise au travail du
     jour pour une information qui ne concerne que l'administrateur, et
     seulement quand une action est attendue. Désormais :
       • licence en règle → rien ;
       • échéance dans 30 jours ou moins → une ligne, aux administrateurs ;
       • échue (période de grâce) → le bandeau global (layouts.partials) ;
       • expirée → l'application redirige déjà vers la page Licence.
     Le détail (progression, SMS restants) est sur la page Licence. --}}
@php
    $licenseSvc = app(\App\Services\LicenseService::class);
    $lic = $licenseSvc->isEnabled() && Gate::allows('admin.L') ? $licenseSvc->current() : null;
@endphp
@if($lic && $licenseSvc->status() === \App\Services\LicenseService::STATUS_ACTIVE && $lic->days_remaining <= 30)
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-800 not-italic">
        <span>
            <i class="fa-solid fa-hourglass-half mr-2"></i>
            {{ __('Abonnement : échéance le :date (:n jours).', ['date' => $lic->expires_at->translatedFormat('d M Y'), 'n' => $lic->days_remaining]) }}
        </span>
        <a href="{{ route('license.edit') }}" class="font-black text-amber-700 no-underline hover:underline">{{ __('Renouveler') }} →</a>
    </div>
@endif
