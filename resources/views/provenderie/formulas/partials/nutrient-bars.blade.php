{{--
    JAUGES NUTRITIONNELLES — rendu unique, partagé par la liste et la fiche.

    Chaque écran portait sa propre pondération et ses propres cibles de repli.
    Ici, tout vient de Formula::nutritionalComparison().

    @param array $comparison  cf. Formula::nutritionalComparison()
    @param array $only        clefs de nutriments à afficher (toutes par défaut)
    @param bool  $dark        variante sur fond sombre
--}}
@php
    $keys = $only ?? array_keys($comparison);
    $dark = $dark ?? false;
@endphp

<div class="space-y-3">
    @foreach($keys as $key)
        @php $row = $comparison[$key] ?? null; @endphp
        @continue(! $row)

        <div class="space-y-1">
            <div class="flex justify-between items-baseline text-[8px] font-black uppercase italic gap-2">
                <span class="{{ $dark ? 'opacity-50' : 'text-slate-400' }}">{{ __($row['label']) }}</span>

                @if(! $row['complete'])
                    {{-- Teneur incomplète : afficher « 0 face à la cible » dessinait
                         une carence qui n'existe que dans la saisie. --}}
                    <span class="{{ $dark ? 'text-amber-300' : 'text-amber-600' }} normal-case"
                          title="{{ __('Teneur manquante au laboratoire') }} : {{ implode(', ', array_slice($row['missing'], 0, 4)) }}">
                        {{ __('Non analysé') }}
                    </span>
                @elseif($row['target'] === null)
                    <span class="{{ $dark ? 'text-white' : 'text-slate-800' }}">
                        {{ number_format($row['real'], $row['decimals'], ',', ' ') }}
                        <small class="opacity-40 normal-case">{{ $row['unit'] }} · {{ __('pas de cible') }}</small>
                    </span>
                @else
                    <span class="{{ $dark ? 'text-white' : 'text-slate-800' }}">
                        {{ number_format($row['real'], $row['decimals'], ',', ' ') }} /
                        {{ number_format($row['target'], $row['decimals'], ',', ' ') }}
                        <small class="opacity-40">{{ $row['unit'] }}</small>
                    </span>
                @endif
            </div>

            <div class="h-1.5 {{ $dark ? 'bg-slate-800' : 'bg-slate-200' }} rounded-full overflow-hidden">
                @if($row['ratio'] !== null)
                    <div @class([
                            'h-full transition-all duration-1000',
                            'bg-emerald-500' => $row['ratio'] >= 0.95 && $row['ratio'] <= 1.10,
                            'bg-amber-400'   => $row['ratio'] > 1.10,
                            'bg-red-500'     => $row['ratio'] < 0.95,
                        ])
                        style="width: {{ min($row['ratio'] * 100, 100) }}%"></div>
                @else
                    <div class="h-full w-full bg-slate-300/40"
                         style="background-image: repeating-linear-gradient(45deg, transparent, transparent 4px, rgba(148,163,184,.5) 4px, rgba(148,163,184,.5) 8px)"></div>
                @endif
            </div>
        </div>
    @endforeach
</div>
