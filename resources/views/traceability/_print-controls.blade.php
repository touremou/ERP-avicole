{{--
    Barre d'actions partagée des étiquettes : sélecteur copies / colonnes
    (aperçu reconfigurable), bouton imprimer, retour. L'impression automatique
    n'a lieu que si le paramètre etiquettes.autoprint est activé.

    Attend : $copies (int), $columns (int) calculés par la vue appelante.
--}}
<div class="actions no-print">
    <form method="GET" class="cfg">
        <label>{{ __('Copies') }}
            <input type="number" name="copies" min="1" max="60" value="{{ $copies }}">
        </label>
        <label>{{ __('Colonnes') }}
            <select name="cols">
                @for($c = 1; $c <= 4; $c++)<option value="{{ $c }}" @selected($columns === $c)>{{ $c }}</option>@endfor
            </select>
        </label>
        <button type="submit" class="ghost">{{ __('Aperçu') }}</button>
    </form>
    <button type="button" onclick="window.print()">🖨 {{ __('Imprimer') }}</button>
    <a href="#" onclick="history.back();return false;" class="back">{{ __('Retour') }}</a>
</div>

@if(setting('etiquettes.autoprint', false))
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
@endif
