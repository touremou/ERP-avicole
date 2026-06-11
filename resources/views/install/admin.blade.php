<x-install-layout :step="4">
    <h2>Compte administrateur & entreprise</h2>
    <p class="help">Configurez le compte administrateur principal. Le compte de démonstration <code>admin@admin.com / password</code> sera remplacé par celui-ci.</p>

    @if ($errors->any())
        <div class="alert alert-error">
            <ul style="margin:0; padding-left:18px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('install.admin.store') }}">
        @csrf

        <div class="field">
            <label for="company_name">Nom de l'entreprise</label>
            <input type="text" name="company_name" id="company_name" value="{{ old('company_name', $companyName) }}">
        </div>

        <div class="field">
            <label for="admin_name">Nom complet de l'administrateur</label>
            <input type="text" name="admin_name" id="admin_name" value="{{ old('admin_name', 'Administrateur') }}">
        </div>

        <div class="field">
            <label for="admin_email">Adresse e-mail</label>
            <input type="email" name="admin_email" id="admin_email" value="{{ old('admin_email') }}">
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="admin_password">Mot de passe</label>
                <input type="password" name="admin_password" id="admin_password">
                <div class="help">8 caractères minimum.</div>
            </div>
            <div class="field">
                <label for="admin_password_confirmation">Confirmation</label>
                <input type="password" name="admin_password_confirmation" id="admin_password_confirmation">
            </div>
        </div>

        <div class="field checkbox-row">
            <input type="checkbox" name="remove_demo_account" id="remove_demo_account" value="1" checked>
            <label for="remove_demo_account" style="margin:0;">Supprimer le compte de démonstration <code>user@users.com</code></label>
        </div>

        <div class="actions">
            <span></span>
            <button type="submit" class="btn">Continuer</button>
        </div>
    </form>
</x-install-layout>
