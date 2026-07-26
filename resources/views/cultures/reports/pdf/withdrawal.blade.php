<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Délai avant récolte") }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .sub { font-size: 8px; color: #64748b; margin-bottom: 10px; }
        .warn { background: #1e293b; color: #fff; padding: 8px 10px; font-size: 7.5px; line-height: 1.5; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; text-align: left; padding: 5px; font-size: 7px; text-transform: uppercase; }
        td { padding: 5px; border-bottom: 1px solid #f1f5f9; }
        .depasse { color: #e11d48; font-weight: bold; }
        .a_verifier { color: #d97706; font-weight: bold; }
        .conforme { color: #059669; }
        .counts td { border: none; padding: 3px 10px 3px 0; font-size: 8px; }
    </style>
</head>
<body>
    <h1>{{ __("Délai avant récolte — traitements suivis d'une récolte") }}</h1>
    <p class="sub">
        {{ __("Depuis le") }} {{ $since->format('d/m/Y') }} ·
        {{ __("fenêtre de :n jours après application", ['n' => $window]) }} ·
        {{ __("édité le") }} {{ now()->format('d/m/Y H:i') }}
    </p>

    {{-- L'avertissement fait partie du document : sorti de l'écran, un tableau
         de zéros se lirait comme une attestation de conformité. --}}
    <div class="warn">
        {{ __("Le délai avant récolte n'était pas enregistré avant sa correction : les traitements antérieurs n'en portent aucun en base, et rien ne peut le reconstituer. « Délai dépassé » est un constat établi ; « À vérifier » signifie délai inconnu, à confronter à la notice du produit. Un total de zéro dépassement ne prouve pas la conformité de l'historique.") }}
    </div>

    <table class="counts">
        <tr>
            <td class="depasse">{{ __("Délai dépassé") }} : {{ $counts['depasse'] }}</td>
            <td class="a_verifier">{{ __("À vérifier") }} : {{ $counts['a_verifier'] }}</td>
            <td class="conforme">{{ __("Conforme") }} : {{ $counts['conforme'] }}</td>
            <td>{{ __("Traitements lus") }} : {{ $treatments }}</td>
        </tr>
    </table>

    <br>

    <table>
        <thead>
            <tr>
                <th>{{ __("Verdict") }}</th>
                <th>{{ __("Cycle") }}</th>
                <th>{{ __("Parcelle") }}</th>
                <th>{{ __("Produit") }}</th>
                <th>{{ __("Application") }}</th>
                <th>{{ __("Récolte") }}</th>
                <th>{{ __("Écart") }}</th>
                <th>{{ __("DAR en base") }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td class="{{ $row['verdict'] }}">
                        @switch($row['verdict'])
                            @case('depasse') {{ __("Délai dépassé") }} @break
                            @case('a_verifier') {{ __("À vérifier") }} @break
                            @default {{ __("Conforme") }}
                        @endswitch
                    </td>
                    <td>{{ $row['cycle']->code ?? '—' }}</td>
                    <td>{{ $row['cycle']->plot->name ?? '—' }}</td>
                    <td>{{ $row['treatment']->name }}</td>
                    <td>{{ $row['treatment']->input_date->format('d/m/Y') }}</td>
                    <td>{{ $row['harvest']->harvest_date->format('d/m/Y') }}</td>
                    <td>{{ $row['gap_days'] }} {{ __("j") }}</td>
                    <td>{{ $row['dar'] ? $row['dar'] . ' ' . __("j") : __("non enregistré") }}</td>
                </tr>
            @empty
                <tr><td colspan="8">{{ __("Aucune récolte n'a suivi un traitement phytosanitaire dans la fenêtre choisie.") }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
