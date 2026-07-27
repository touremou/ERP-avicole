{{--
    VERDICT ÉCONOMIQUE — rendu unique (cf. Formula::economicVerdict()).

    La liste comparait au prix cible du référentiel (avec un repli à 4 500) et la
    fiche tranchait sur un « coût < 5 000 » codé en dur : une même formule
    pouvait être verte ici et « À RÉVISER » là. Sans prix cible, on n'affiche
    plus de verdict — une absence de référence n'est pas une performance.

    @param array $verdict  cf. Formula::economicVerdict()
--}}
<div class="text-right">
    <p class="text-xl font-black text-slate-900 italic tracking-tighter leading-none">
        {{ number_format($verdict['cost'], 0, ',', ' ') }}
        <small class="text-[10px]">{{ currency() }}/kg</small>
    </p>

    @if($verdict['status'] === 'unknown')
        <p class="text-[7px] font-black uppercase mt-1 italic text-slate-400">
            <i class="fa-solid fa-circle-info mr-1"></i>{{ $verdict['label'] }}
        </p>
    @else
        <p @class([
            'text-[7px] font-black uppercase mt-1 italic',
            'text-emerald-600' => $verdict['status'] === 'under',
            'text-slate-500'   => $verdict['status'] === 'near',
            'text-red-500'     => $verdict['status'] === 'over',
        ])>
            {{ $verdict['label'] }}
            ({{ $verdict['diff'] <= 0 ? '−' : '+' }}{{ number_format(abs($verdict['diff']), 0, ',', ' ') }} {{ currency() }}/kg)
        </p>
    @endif
</div>
