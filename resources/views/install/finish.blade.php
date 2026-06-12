<x-install-layout :step="5">
    <h2>{{ __("🎉 Installation terminée") }}</h2>

    @if ($alreadyInstalled ?? false)
        <p>{{ __("L'application est déjà installée.") }}</p>
    @else
        <p>{{ config('app.name', 'AviSmart') }} {{ __("est prêt à l'emploi. Vous pouvez vous connecter avec le compte administrateur que vous venez de configurer.") }}</p>

        <div class="alert alert-success">
            {{ __("Pensez à exécuter") }} <code>php artisan config:cache</code>, <code>php artisan route:cache</code> {{ __("et") }} <code>php artisan view:cache</code> {{ __("pour optimiser les performances en production (voir DEPLOYMENT.md).") }}
        </div>
    @endif

    <div class="actions">
        <span></span>
        <a href="{{ route('login') }}" class="btn">{{ __("Aller à la connexion") }}</a>
    </div>
</x-install-layout>
