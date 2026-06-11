<x-install-layout :step="5">
    <h2>🎉 Installation terminée</h2>

    @if ($alreadyInstalled ?? false)
        <p>L'application est déjà installée.</p>
    @else
        <p>{{ config('app.name', 'AviSmart') }} est prêt à l'emploi. Vous pouvez vous connecter avec le compte administrateur que vous venez de configurer.</p>

        <div class="alert alert-success">
            Pensez à exécuter <code>php artisan config:cache</code>, <code>php artisan route:cache</code> et <code>php artisan view:cache</code> pour optimiser les performances en production (voir DEPLOYMENT.md).
        </div>
    @endif

    <div class="actions">
        <span></span>
        <a href="{{ route('login') }}" class="btn">Aller à la connexion</a>
    </div>
</x-install-layout>
