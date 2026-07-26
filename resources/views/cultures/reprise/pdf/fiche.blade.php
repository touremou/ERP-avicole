<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __("Fiche de reprise d'historique — Cultures") }}</title>
    <style>
        /*
         * Feuille destinée à être IMPRIMÉE, remplie au stylo, PHOTOGRAPHIÉE et
         * envoyée par WhatsApp. Tout est calibré pour ce parcours :
         *  - bordures franches (0.8pt noir) : un filet fin disparaît en photo ;
         *  - lignes de 22px : la hauteur d'une écriture manuscrite adulte ;
         *  - en-têtes noirs sur blanc, aucun gris clair sous 15 % ;
         *  - une section par page, pour photographier une page à la fois sans
         *    avoir à cadrer un A3 replié.
         */
        @page { margin: 10mm 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #000; }

        .head { border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 8px; }
        .head h1 { font-size: 14px; margin: 0; text-transform: uppercase; }
        .head .meta { font-size: 8px; margin-top: 3px; }

        .idbox { border: 1.5px solid #000; padding: 6px 8px; margin-bottom: 10px; font-size: 9px; }
        .idbox .line { margin: 4px 0; }
        .fill { display: inline-block; border-bottom: 1px solid #000; min-width: 130px; }

        .notice { border: 1.5px solid #000; padding: 6px 8px; margin-bottom: 10px; font-size: 8px; line-height: 1.5; }
        .notice b { text-transform: uppercase; }

        .section { page-break-inside: avoid; margin-bottom: 4px; }
        .section h2 { font-size: 11px; margin: 0 0 3px; text-transform: uppercase; }
        .section .hint { font-size: 7.5px; margin: 0 0 5px; line-height: 1.4; }

        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th {
            border: 0.8px solid #000; background: #000; color: #fff;
            font-size: 6.5px; padding: 4px 2px; text-align: left;
            text-transform: uppercase; word-wrap: break-word;
        }
        table.grid th .req { color: #fff; }
        /* La cellule vide EST le champ à remplir : hauteur = confort d'écriture. */
        table.grid td { border: 0.8px solid #000; height: 24px; }
        table.grid tr:nth-child(even) td { background: #f2f2f2; }

        .choices { font-size: 7px; margin: 4px 0 0; line-height: 1.5; }
        .choices .row { margin: 2px 0; }
        .choices .name { text-transform: uppercase; }
        .choices .strict { font-weight: bold; }

        .footer { margin-top: 8px; border-top: 1px solid #000; padding-top: 5px; font-size: 7px; line-height: 1.5; }
        /* Bandeau répété sur CHAQUE page : chacune est photographiée et envoyée
           séparément, donc chacune doit porter son origine. */
        .strip { border: 1.2px solid #000; padding: 4px 6px; margin-bottom: 6px; font-size: 8px; }
        .pagebreak { page-break-after: always; }
    </style>
</head>
<body>

    <div class="head">
        <h1>{{ __("Fiche de reprise d'historique — Cultures") }}</h1>
        <p class="meta">
            {{ __("À remplir au stylo, puis photographier chaque page et envoyer par WhatsApp.") }}
            &nbsp;·&nbsp; {{ __("Éditée le") }} {{ $generated_at->format('d/m/Y') }}
        </p>
    </div>

    {{-- QUI a rempli, OÙ, QUAND. Sans cet en-tête, une photo reçue au bureau est
         une feuille anonyme : on ne sait ni de quel site elle vient, ni à qui
         demander une précision sur une écriture illisible. --}}
    <div class="idbox">
        <div class="line">
            <b>{{ __("Site / Ferme") }} :</b> <span class="fill">&nbsp;</span>
            &nbsp;&nbsp;<b>{{ __("Rempli par") }} :</b> <span class="fill">&nbsp;</span>
        </div>
        <div class="line">
            <b>{{ __("Date de remplissage") }} :</b> <span class="fill">&nbsp;</span>
            &nbsp;&nbsp;<b>{{ __("Page") }} :</b> <span class="fill" style="min-width:50px">&nbsp;</span> / <span class="fill" style="min-width:50px">&nbsp;</span>
        </div>
    </div>

    <div class="notice">
        <b>{{ __("Comment remplir") }}</b><br>
        {{ __("1. Les colonnes marquées d'une étoile (*) sont obligatoires. Les autres peuvent rester vides.") }}<br>
        {{ __("2. Les dates s'écrivent JJ/MM/AAAA — par exemple 12/08/2026.") }}<br>
        {{ __("3. LES CODES FONT LE LIEN : un code écrit en section A doit être recopié à l'identique en section B, et un code de cycle de la section B doit être recopié en C et D. C'est ce qui rattache chaque activité à sa culture.") }}<br>
        {{ __("4. Écrivez en MAJUSCULES et espacez les chiffres : la fiche sera relue sur une photo.") }}<br>
        {{ __("5. Si vous ne savez pas, laissez vide plutôt que de deviner. Une donnée inventée est pire qu'une donnée absente.") }}
    </div>

    @foreach($sections as $index => $section)
        {{-- Bandeau d'origine sur les pages 2 et suivantes. La page 1 porte déjà
             le bloc d'identification complet. Sans lui, une photo de la page 3
             reçue seule sur WhatsApp est une feuille anonyme : ni site, ni auteur
             à qui demander une précision sur une écriture douteuse. --}}
        @if($index > 0)
            <div class="strip">
                <b>{{ __("Site") }} :</b> <span class="fill" style="min-width:110px">&nbsp;</span>
                &nbsp;&nbsp;<b>{{ __("Rempli par") }} :</b> <span class="fill" style="min-width:110px">&nbsp;</span>
                &nbsp;&nbsp;<b>{{ __("Date") }} :</b> <span class="fill" style="min-width:80px">&nbsp;</span>
                &nbsp;&nbsp;<b>{{ __("Fiche de reprise — Cultures") }}</b>
            </div>
        @endif

        <div class="section">
            <h2>{{ $section['title'] }}</h2>
            <p class="hint">{{ $section['hint'] }}</p>

            @php $total = collect($section['columns'])->sum('weight'); @endphp
            <table class="grid">
                <thead>
                    <tr>
                        @foreach($section['columns'] as $column)
                            <th style="width: {{ round($column['weight'] / $total * 100, 2) }}%">
                                {{ $column['label'] }}@if($column['required'])<span class="req"> *</span>@endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @for($row = 1; $row <= $section['rows']; $row++)
                        <tr>
                            @foreach($section['columns'] as $column)
                                <td></td>
                            @endforeach
                        </tr>
                    @endfor
                </tbody>
            </table>

            {{-- Sur le papier il n'y a pas de liste déroulante : si les valeurs
                 acceptées ne sont pas sous les yeux, le technicien écrit
                 « engrai » ou « vendu », et la ligne sera refusée à l'import. --}}
            @if($section['choices'] !== [])
                <div class="choices">
                    @foreach($section['choices'] as $choice)
                        <div class="row">
                            <span class="name {{ $choice['strict'] ? 'strict' : '' }}">{{ $choice['label'] }}</span> —
                            @if($choice['strict'])
                                {{ __("écrire EXACTEMENT l'un de") }} :
                            @else
                                {{ __("exemples courants (autre valeur possible)") }} :
                            @endif
                            {{ implode(' · ', array_slice($choice['values'], 0, 24)) }}@if(count($choice['values']) > 24) …@endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @if($index < count($sections) - 1)
            <div class="pagebreak"></div>
        @endif
    @endforeach

    <div class="footer">
        <b>{{ __("Au bureau") }} :</b>
        {{ __("recopier ces lignes dans le modèle Excel (Cultures › Reprise d'historique › Télécharger le modèle), onglet par onglet et dans le même ordre de colonnes, puis téléverser pour analyse avant d'importer.") }}
        {{ __("Rien n'est enregistré avant la validation explicite.") }}
    </div>

</body>
</html>
