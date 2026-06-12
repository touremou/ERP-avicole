<x-install-layout :step="3">
    <h2>{{ __("Initialisation de la base de données") }}</h2>
    <p class="help">{{ __("Cette étape crée les tables et charge les données de référence (espèces, normes de production, modules…).") }}</p>

    @if (isset($error))
        <div class="alert alert-error">
            {{ __("Une erreur s'est produite :") }} {{ $error }}
        </div>
        <pre class="output">{{ $output }}</pre>
        <div class="actions">
            <span></span>
            <form method="POST" action="{{ route('install.migrate.run') }}">
                @csrf
                <button type="submit" class="btn">{{ __("Réessayer") }}</button>
            </form>
        </div>
    @elseif (isset($success))
        <div class="alert alert-success">
            {{ __("Base de données initialisée avec succès.") }}
        </div>
        <pre class="output">{{ $output }}</pre>
        <div class="actions">
            <span></span>
            <a href="{{ route('install.admin') }}" class="btn">{{ __("Continuer") }}</a>
        </div>
    @else
        <p>{{ __("Cliquez sur le bouton ci-dessous pour lancer les migrations et le chargement des données de référence. Cette opération peut prendre quelques instants.") }}</p>
        <div class="actions">
            <span></span>
            <form method="POST" action="{{ route('install.migrate.run') }}">
                @csrf
                <button type="submit" class="btn">{{ __("Lancer l'initialisation") }}</button>
            </form>
        </div>
    @endif
</x-install-layout>
