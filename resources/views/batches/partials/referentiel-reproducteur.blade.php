{{-- RÉFÉRENTIEL REPRODUCTEURS — réel contre guide de souche, semaine par semaine
     (App\Services\ReferentielReproducteur). Une cellule : le réel, et en
     dessous la valeur du guide ; « — » quand l'une ou l'autre manque. --}}
@php
    $colonnes = [
        ['cle' => 'laying',        'label' => __('Ponte HD'),        'unite' => '%',  'mieux' => 'haut'],
        ['cle' => 'hatching_eggs', 'label' => __('Œufs incubables'), 'unite' => '%',  'mieux' => 'haut'],
        ['cle' => 'fertility',     'label' => __('Fertilité'),       'unite' => '%',  'mieux' => 'haut'],
        ['cle' => 'hatchability',  'label' => __('Éclosion'),        'unite' => '%',  'mieux' => 'haut'],
        ['cle' => 'feed',          'label' => __('Aliment'),         'unite' => 'g/j', 'mieux' => null],
        ['cle' => 'water',         'label' => __('Eau'),             'unite' => 'ml/j', 'mieux' => null],
        ['cle' => 'mortality',     'label' => __('Mortalité + réforme'), 'unite' => '%', 'mieux' => 'bas'],
    ];
    $nombre = fn ($v, $d = 1) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ');
    $alerte = $referentiel['alerte'];
    $seuil  = \App\Models\ProductionNorm::ecartDePonteTolere();
@endphp

<div class="mb-8 bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
        <h3 class="text-[10px] font-black uppercase text-slate-500 tracking-widest flex items-center gap-2">
            <i class="fa-solid fa-dna text-violet-500"></i> {{ __("Réel vs Référentiel") }}
            <span class="text-slate-400">· {{ $referentiel['souche'] ?? __('souche') }}</span>
        </h3>
        <span class="text-[8px] font-black uppercase text-slate-400 italic">{{ __("Alerte si la ponte HD passe :n points sous la courbe", ['n' => $nombre($seuil, 0)]) }}</span>
    </div>

    @if($alerte)
        <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-[10px] font-black uppercase">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            {{ __("Semaine :s : ponte HD :reel % pour :guide % au guide (:ecart points sous la courbe)", ['s' => $alerte['semaine'], 'reel' => $nombre($alerte['reel']), 'guide' => $nombre($alerte['guide']), 'ecart' => $nombre($alerte['ecart'])]) }}
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-[10px] font-black">
            <thead>
                <tr class="text-slate-400 uppercase text-[8px] tracking-widest">
                    <th class="text-left py-2 pr-3">{{ __("Semaine") }}</th>
                    <th class="text-right py-2 px-2">{{ __("Poids (g)") }}</th>
                    @foreach($colonnes as $c)
                        <th class="text-right py-2 px-2">{{ $c['label'] }} <span class="opacity-60">({{ $c['unite'] }})</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach(array_reverse($referentiel['semaines']) as $s)
                    @php $guide = $s['guide']; $reel = $s['reel']; @endphp
                    <tr>
                        <td class="py-2 pr-3 text-slate-700">S{{ $s['semaine'] }} <span class="block text-[8px] text-slate-400">{{ $s['du']->format('d/m') }} – {{ $s['au']->format('d/m') }}</span></td>
                        <td class="py-2 px-2 text-right text-slate-700">
                            {{ $nombre($reel['weight'], 0) }}
                            <span class="block text-[8px] text-slate-400">{{ __("♂ :m · ♀ :f", ['m' => $nombre($guide['weight_male'] ?? null, 0), 'f' => $nombre($guide['weight_female'] ?? null, 0)]) }}</span>
                        </td>
                        @foreach($colonnes as $c)
                            @php
                                $r = $reel[$c['cle']] ?? null;
                                $g = $guide[$c['cle']] ?? null;
                                $enRetard = $r !== null && $g !== null && $c['mieux'] !== null
                                    && ($c['mieux'] === 'haut' ? $r < $g : $r > $g);
                                $ponteEnAlerte = $c['cle'] === 'laying' && $alerte && $alerte['semaine'] === $s['semaine'];
                            @endphp
                            <td @class(['py-2 px-2 text-right', 'text-rose-600' => $ponteEnAlerte, 'text-amber-600' => ! $ponteEnAlerte && $enRetard, 'text-slate-700' => ! $ponteEnAlerte && ! $enRetard])>
                                {{ $nombre($r, $c['cle'] === 'mortality' ? 2 : 1) }}
                                <span class="block text-[8px] text-slate-400">{{ __("guide") }} {{ $nombre($g, $c['cle'] === 'mortality' ? 2 : 1) }}</span>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-[8px] font-black text-slate-300 uppercase italic mt-3">{{ __("Valeurs du guide : Paramètres → Normes de la souche. Poids réel : moyenne pesée (le pointage ne sépare pas mâles et femelles).") }}</p>
</div>
