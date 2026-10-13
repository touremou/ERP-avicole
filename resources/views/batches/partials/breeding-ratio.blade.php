{{--
    PROPORTION DE REPRODUCTEURS — bandeau unique de la création de bande, de
    sa modification et de la planification.

    Il s'intitulait « Ratio de coquage » (le vocabulaire du poulet) pour toutes
    les espèces, et n'était jugé qu'à la planification, sur la norme du poulet.
    La référence vient de l'espèce (Species::ratioReproducteurs) ; le jugement,
    de resources/js/breeding-ratio.js. Chaque écran appelle
    `window.afficherRatioReproducteurs(mâles, femelles, slugEspèce)`.
--}}
<div class="{{ $classe ?? '' }} flex items-center justify-between gap-4 p-4 bg-white/50 rounded-2xl border border-indigo-100">
    <div class="flex items-center gap-3">
        <div class="p-3 bg-indigo-500 rounded-xl text-white"><i class="fa-solid fa-venus-mars"></i></div>
        <div>
            <p class="text-[8px] font-black uppercase text-slate-400 leading-none mb-1">{{ __("Proportion mâles / femelles") }}</p>
            <p class="text-xl font-black text-indigo-600 leading-none" id="ratio_display">—</p>
            <p class="text-[9px] font-bold text-indigo-400 normal-case not-italic mt-1 leading-none" id="ratio_reading"></p>
        </div>
    </div>
    <div class="text-right">
        <div id="ratio_status" class="inline-block px-4 py-2 rounded-xl text-[9px] font-black uppercase italic tracking-widest bg-slate-100 text-slate-400">{{ __("En attente...") }}</div>
        <p class="text-[9px] font-bold text-slate-500 normal-case not-italic mt-1 leading-snug max-w-[220px] ml-auto" id="ratio_advice"></p>
    </div>
</div>
<script>window.AVISMART_RATIOS = @js(\App\Models\Species::ratiosParSlug());</script>
