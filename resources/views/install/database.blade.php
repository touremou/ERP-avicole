<x-install-layout :step="2">
    <h2>Configuration de la base de données</h2>
    <p class="help">Renseignez les informations de connexion. La base sera créée automatiquement si elle n'existe pas (MySQL).</p>

    @if ($errors->any())
        <div class="alert alert-error">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('install.database.store') }}" id="db-form">
        @csrf

        <div class="field">
            <label for="connection">Type de base de données</label>
            <select name="connection" id="connection" onchange="toggleMysqlFields()">
                <option value="mysql" {{ old('connection', $current['connection']) === 'mysql' ? 'selected' : '' }}>MySQL / MariaDB (recommandé en production)</option>
                <option value="sqlite" {{ old('connection', $current['connection']) === 'sqlite' ? 'selected' : '' }}>SQLite (test / petite installation)</option>
            </select>
        </div>

        <div id="mysql-fields">
            <div class="grid-2">
                <div class="field">
                    <label for="host">Hôte</label>
                    <input type="text" name="host" id="host" value="{{ old('host', $current['host']) }}">
                </div>
                <div class="field">
                    <label for="port">Port</label>
                    <input type="text" name="port" id="port" value="{{ old('port', $current['port']) }}">
                </div>
            </div>
            <div class="field">
                <label for="username">Utilisateur</label>
                <input type="text" name="username" id="username" value="{{ old('username', $current['username']) }}">
            </div>
            <div class="field">
                <label for="password">Mot de passe</label>
                <input type="password" name="password" id="password">
            </div>
        </div>

        <div class="field">
            <label for="database">Nom de la base de données</label>
            <input type="text" name="database" id="database" value="{{ old('database', $current['database']) }}">
            <div class="help">Lettres, chiffres et underscores uniquement.</div>
        </div>

        <div class="actions">
            <span></span>
            <button type="submit" class="btn">Tester et continuer</button>
        </div>
    </form>

    <script>
        function toggleMysqlFields() {
            const isMysql = document.getElementById('connection').value === 'mysql';
            document.getElementById('mysql-fields').style.display = isMysql ? 'block' : 'none';
        }
        toggleMysqlFields();
    </script>
</x-install-layout>
