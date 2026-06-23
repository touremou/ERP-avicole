{{-- État vide partagé pour les graphiques de la fiche lot : affiché tant
     qu'aucun pointage quotidien n'alimente la courbe. --}}
<div class="h-[280px] flex flex-col items-center justify-center text-center px-4">
    <i class="fas fa-chart-line text-slate-200 text-4xl mb-4"></i>
    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest italic">{{ __("Aucun pointage enregistré") }}</p>
    <p class="text-[9px] font-bold text-slate-300 normal-case mt-2 max-w-[220px]">{{ __("La courbe apparaîtra dès le premier suivi quotidien du lot.") }}</p>
</div>
