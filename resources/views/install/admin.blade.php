<x-install-layout :step="4">
    <h2>{{ __("Compte administrateur & entreprise") }}</h2>
    <p class="help">{{ __("Créez le compte administrateur principal. Les comptes de démonstration créés à l’étape précédente — tous au mot de passe public « password » — seront supprimés à la validation de cette étape.") }}</p>

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
            <label for="company_name">{{ __("Nom de l'entreprise") }}</label>
            <input type="text" name="company_name" id="company_name" value="{{ old('company_name', $companyName) }}">
        </div>

        <div class="field">
            <label for="admin_name">{{ __("Nom complet de l'administrateur") }}</label>
            <input type="text" name="admin_name" id="admin_name" value="{{ old('admin_name', 'Administrateur') }}">
        </div>

        <div class="field">
            <label for="admin_email">{{ __("Adresse e-mail") }}</label>
            <input type="email" name="admin_email" id="admin_email" value="{{ old('admin_email') }}">
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="admin_password">{{ __("Mot de passe") }}</label>
                <input type="password" name="admin_password" id="admin_password">
                <div class="help">{{ __("8 caractères minimum.") }}</div>
            </div>
            <div class="field">
                <label for="admin_password_confirmation">{{ __("Confirmation") }}</label>
                <input type="password" name="admin_password_confirmation" id="admin_password_confirmation">
            </div>
        </div>

        <div class="actions">
            <span></span>
            <button type="submit" class="btn">{{ __("Continuer") }}</button>
        </div>
    </form>
</x-install-layout>
