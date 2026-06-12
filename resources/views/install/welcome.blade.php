<x-install-layout :step="1">
    <h2>{{ __("Vérification des prérequis") }}</h2>
    <p class="help">{{ __("L'assistant vérifie que le serveur dispose de tout le nécessaire avant de continuer.") }}</p>

    <ul class="checklist">
        @foreach ($checks as $check)
            <li>
                <span>
                    {{ $check['label'] }}
                    <div class="help">{{ $check['detail'] }}</div>
                </span>
                @if ($check['status'])
                    <span class="badge badge-ok">OK</span>
                @else
                    <span class="badge badge-fail">{{ $check['required'] ? __("Requis") : __("Recommandé") }}</span>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="actions">
        <span></span>
        @if ($canProceed)
            <a href="{{ route('install.database') }}" class="btn">{{ __("Continuer") }}</a>
        @else
            <button class="btn" disabled>{{ __("Corrigez les éléments requis ci-dessus") }}</button>
        @endif
    </div>
</x-install-layout>
