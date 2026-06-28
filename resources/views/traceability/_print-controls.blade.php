{{--
    Barre d'actions partagée des étiquettes : format de page + copies (aperçu
    reconfigurable), bouton imprimer, retour. L'impression automatique n'a lieu
    que si etiquettes.autoprint est activé. Attend $cfg (LabelConfig::current()).
--}}
@php
    $formats = ['seule' => 'Étiquette seule', 'a4' => 'Feuille A4', 'a5' => 'Feuille A5', 'a6' => 'Feuille A6'];
@endphp
<div class="actions no-print">
    <form method="GET" class="cfg">
        <label>{{ __('Format') }}
            <select name="format">
                @foreach($formats as $key => $lbl)<option value="{{ $key }}" @selected($cfg['format'] === $key)>{{ __($lbl) }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('Copies') }}
            <input type="number" name="copies" min="1" max="200" value="{{ $cfg['copies'] }}">
        </label>
        <button type="submit" class="ghost">{{ __('Aperçu') }}</button>
    </form>
    <button type="button" onclick="window.print()">🖨 {{ __('Imprimer') }}</button>
    <a href="#" onclick="history.back();return false;" class="back">{{ __('Retour') }}</a>
</div>

@if($cfg['autoprint'])
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
@endif
