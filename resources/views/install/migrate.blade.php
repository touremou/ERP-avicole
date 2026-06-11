<x-install-layout :step="3">
    <h2>Initialisation de la base de données</h2>
    <p class="help">Cette étape crée les tables et charge les données de référence (espèces, normes de production, modules…).</p>

    @if (isset($error))
        <div class="alert alert-error">
            Une erreur s'est produite : {{ $error }}
        </div>
        <pre class="output">{{ $output }}</pre>
        <div class="actions">
            <span></span>
            <form method="POST" action="{{ route('install.migrate.run') }}">
                @csrf
                <button type="submit" class="btn">Réessayer</button>
            </form>
        </div>
    @elseif (isset($success))
        <div class="alert alert-success">
            Base de données initialisée avec succès.
        </div>
        <pre class="output">{{ $output }}</pre>
        <div class="actions">
            <span></span>
            <a href="{{ route('install.admin') }}" class="btn">Continuer</a>
        </div>
    @else
        <p>Cliquez sur le bouton ci-dessous pour lancer les migrations et le chargement des données de référence. Cette opération peut prendre quelques instants.</p>
        <div class="actions">
            <span></span>
            <form method="POST" action="{{ route('install.migrate.run') }}">
                @csrf
                <button type="submit" class="btn">Lancer l'initialisation</button>
            </form>
        </div>
    @endif
</x-install-layout>
