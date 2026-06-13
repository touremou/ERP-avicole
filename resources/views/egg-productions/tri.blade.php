<x-app-layout>
    <div class="py-12 italic font-black text-left bg-slate-50 min-h-screen">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-8">
            
            <div class="flex justify-between items-end">
                <h2 class="text-3xl font-black text-slate-800 uppercase italic tracking-tighter flex items-center gap-4 m-0">
                    <span class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-xl rotate-3">
                        <i class="fa-solid fa-scale-balanced text-lg"></i>
                    </span>
                    <div>
                        <span class="text-sm text-slate-400 block tracking-widest not-italic font-bold">{{ __("SESSION DE TRI") }}</span>
                        {{ __("LOT") }} <span class="text-blue-600">{{ $batch->code }}</span>
                    </div>
                </h2>
                <div class="text-right">
                    <span id="brut_alv" class="text-xs font-black text-slate-400 italic bg-white px-4 py-2 rounded-full border border-slate-100">0</span>
                </div>
            </div>

            <div class="bg-slate-900 p-10 rounded-[3.5rem] shadow-2xl relative overflow-hidden group">
                <div class="relative z-10 flex flex-col md:flex-row justify-between items-center gap-6">
                    <div class="text-center md:text-left">
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.3em] mb-1 italic m-0">{{ __("Total à ventiler") }}</p>
                        <h3 class="text-7xl font-black text-white tracking-tighter leading-none m-0 mt-2" id="brut_fixe">{{ $eggProduction->total_eggs_collected }}</h3>
                    </div>

                    <div class="flex-1 w-full max-w-md">
                         <div class="flex justify-between mb-2 px-2">
                            <span class="text-[10px] text-slate-500 uppercase font-black italic">{{ __("Progression du tri") }}</span>
                            <span id="reste_count" class="text-[10px] text-emerald-400 uppercase font-black italic">{{ __("Reste : 0") }}</span>
                         </div>
                         <div class="h-4 bg-slate-800 rounded-full overflow-hidden border border-white/5 p-1">
                            <div id="progress_bar" class="h-full bg-emerald-500 rounded-full transition-all duration-500" style="width: 0%"></div>
                         </div>
                    </div>
                </div>
                <i class="fa-solid fa-eggs absolute -right-6 -bottom-6 text-white/5 text-9xl"></i>
            </div>

            @can('production.M')
            <form action="{{ route('egg-productions.update-tri', $eggProduction->id) }}" method="POST" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-2 gap-6">
                    <div class="bg-white p-8 rounded-[3rem] border-2 border-red-50 shadow-sm group hover:border-red-400 transition-all relative overflow-hidden">
                        <label class="block text-[10px] font-black text-red-500 uppercase mb-4 tracking-widest italic leading-none">⚠️ {{ __("Cassés (Unités)") }}</label>
                        <input type="number" min="0" name="broken_eggs" id="broken" 
                               value="{{ old('broken_eggs', $eggProduction->broken_eggs ?? 0) }}" 
                               oninput="updateUI()"
                               class="w-full bg-transparent border-none text-5xl font-black text-center focus:ring-0 p-0 text-slate-800 italic outline-none">
                        <div class="absolute bottom-0 left-0 h-1 w-0 bg-red-500 group-hover:w-full transition-all duration-700"></div>
                    </div>
                    <div class="bg-white p-8 rounded-[3rem] border-2 border-orange-50 shadow-sm group hover:border-orange-400 transition-all relative overflow-hidden">
                        <label class="block text-[10px] font-black text-orange-500 uppercase mb-4 tracking-widest italic leading-none">⚙️ {{ __("Anormaux (Unités)") }}</label>
                        <input type="number" min="0" name="small_eggs" id="small" 
                               value="{{ old('small_eggs', $eggProduction->small_eggs ?? 0) }}" 
                               oninput="updateUI()"
                               class="w-full bg-transparent border-none text-5xl font-black text-center focus:ring-0 p-0 text-slate-800 italic outline-none">
                        <div class="absolute bottom-0 left-0 h-1 w-0 bg-orange-500 group-hover:w-full transition-all duration-700"></div>
                    </div>
                </div>

                <div class="bg-white p-10 rounded-[4rem] border border-slate-100 shadow-xl shadow-slate-200/50">
                    <div class="flex justify-between items-center mb-10">
                        <div class="text-left">
                            <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest italic m-0">{{ __("Ventilation Magasin") }}</h3>
                            <p class="text-[10px] text-blue-500 uppercase font-black italic mt-1 m-0">{{ __("Plateaux de") }} {{ setting('general.eggs_per_tray', 30) }} {{ __("œufs") }}</p>
                        </div>
                        <div id="feedback" class="px-8 py-3 rounded-2xl text-xs font-black uppercase italic shadow-lg transition-all duration-500 transform scale-110">
                            {{ __("Calcul...") }}
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        @foreach(\App\Models\EggProduction::gradeCodes() as $code)
                        @php($c = strtolower($code))
                        <div id="card_{{ $c }}" class="p-4 bg-slate-50 rounded-[2rem] border-2 border-transparent transition-all duration-300">
                            <label class="block text-[9px] font-black text-slate-400 uppercase mb-3 tracking-[0.2em] italic text-center">
                                {{ __("Grade") }} {{ $code }}
                            </label>

                            <div class="flex items-center gap-2">
                                <div class="relative flex-1">
                                    <input type="number" name="grade_{{ $c }}_alv" id="{{ $c }}_alv"
                                        placeholder="0" oninput="this.value = Math.max(0, this.value); updateUI();"
                                        value="{{ floor(old('grade_'.$c, $eggProduction->{'grade_'.$c} ?? 0)) }}"
                                        class="w-full bg-white border border-slate-100 rounded-xl p-2.5 text-xl font-black text-center shadow-inner focus:ring-2 focus:ring-blue-500 italic appearance-none outline-none">
                                    <p class="text-[7px] font-black text-slate-300 uppercase mt-1 text-center tracking-widest m-0">{{ __("Plateaux") }}</p>
                                </div>
                                <div class="text-slate-300 font-black mb-4">/</div>
                                <div class="relative flex-1">
                                    <input type="number" min="0" max="{{ setting('general.eggs_per_tray', 30) - 1 }}" name="grade_{{ $c }}_uni" id="{{ $c }}_uni"
                                        placeholder="0" oninput="this.value = Math.max(0, this.value); updateUI();"
                                        value="{{ round((($eggProduction->{'grade_'.$c} ?? 0) - floor($eggProduction->{'grade_'.$c} ?? 0)) * setting('general.eggs_per_tray', 30)) }}"
                                        class="w-full bg-white border border-slate-100 rounded-xl p-2.5 text-xl font-black text-center shadow-inner focus:ring-2 focus:ring-blue-500 italic appearance-none outline-none">
                                    <p class="text-[7px] font-black text-slate-300 uppercase mt-1 text-center tracking-widest m-0">{{ __("Œufs") }}</p>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-center gap-1.5 bg-blue-600 text-white py-1.5 rounded-lg shadow-sm shadow-blue-200">
                                <span id="count_{{ $c }}" class="text-[11px] font-black leading-none">0</span>
                                <span class="text-[7px] font-black uppercase italic opacity-80 leading-none">{{ __("Total") }}</span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-col gap-4 pt-6">
                    <button type="submit" id="submit-btn" class="w-full bg-slate-900 text-white font-black py-10 rounded-[3.5rem] shadow-2xl uppercase italic tracking-[0.3em] text-sm transition-all hover:bg-blue-600 hover:-translate-y-1 active:scale-95 flex items-center justify-center gap-4 group border-none cursor-pointer">
                        <span>{{ __("Confirmer le Calibrage") }}</span>
                        <i class="fa-solid fa-check-double text-emerald-400 group-hover:rotate-12 transition-transform"></i>
                    </button>
                    <a href="{{ route('egg-productions.index') }}" class="w-full bg-white text-slate-400 font-black py-6 rounded-[3rem] border border-slate-100 text-center uppercase tracking-[0.4em] text-[9px] italic hover:text-slate-800 transition-all no-underline flex items-center justify-center">
                        <i class="fa-solid fa-chevron-left mr-2"></i> {{ __("Retour Dashboard") }}
                    </a>
                </div>
            </form>
            @else
            <div class="bg-white p-20 rounded-[4rem] border border-slate-100 shadow-xl text-center italic font-bold">
                <i class="fas fa-lock text-slate-200 text-6xl mb-6"></i>
                <h3 class="text-xl font-black text-slate-800 uppercase italic mb-2 tracking-tighter">{{ __("Accès Restreint") }}</h3>
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest italic">{{ __("Vous n'avez pas les droits de modification (M) pour effectuer le tri.") }}</p>
                <a href="{{ route('egg-productions.index') }}" class="inline-block mt-8 px-10 py-4 bg-slate-900 text-white rounded-2xl text-[10px] font-black uppercase italic no-underline">{{ __("Retour au Dashboard") }}</a>
            </div>
            @endcan
        </div>
    </div>

<script>
function getValue(id) { 
    const el = document.getElementById(id);
    if (el && el.value < 0) el.value = 0; 
    return el ? Math.max(0, parseFloat(el.value) || 0) : 0; 
}

function updateUI() {
    const brutEl = document.getElementById('brut_fixe');
    const feedback = document.getElementById('feedback');
    const btn = document.getElementById('submit-btn');
    const progressBar = document.getElementById('progress_bar');
    const resteCount = document.getElementById('reste_count');
    const brutAlvDisplay = document.getElementById('brut_alv');

    if (!brutEl || !feedback || !btn) return;
    
    const brutUnites = parseInt(brutEl.innerText);
    let totalGlobalGradesUnites = 0;

    @json(array_map('strtolower', \App\Models\EggProduction::gradeCodes())).forEach(grade => {
        const alv = getValue(`${grade}_alv`);
        const uniInput = document.getElementById(`${grade}_uni`);
        const maxUnits = setting('general.eggs_per_tray', 30) - 1;
        if (uniInput && uniInput.value > maxUnits) uniInput.value = maxUnits;
        const uni = getValue(`${grade}_uni`);

        const totalUnitesGrade = Math.round((alv * setting('general.eggs_per_tray', 30)) + uni);
        totalGlobalGradesUnites += totalUnitesGrade;

        const countEl = document.getElementById(`count_${grade}`);
        const cardEl = document.getElementById(`card_${grade}`);

        if (countEl) countEl.innerText = totalUnitesGrade;

        if (cardEl) {
            if (totalUnitesGrade > 0) {
                cardEl.classList.add('border-blue-500', 'bg-blue-50/30');
                cardEl.classList.remove('bg-slate-50', 'border-transparent');
            } else {
                cardEl.classList.remove('border-blue-500', 'bg-blue-50/30');
                cardEl.classList.add('bg-slate-50', 'border-transparent');
            }
        }
    });

    const pertesUnites = getValue('broken') + getValue('small');
    const totalVentile = totalGlobalGradesUnites + pertesUnites;
    const reste = brutUnites - totalVentile;

    if (progressBar) {
        const progressPercentage = Math.max(0, Math.min(100, (totalVentile / brutUnites) * 100));
        progressBar.style.width = progressPercentage + '%';
    }

    if (resteCount) {
        if (reste < 0) {
            resteCount.innerText = @json(__("Excès :")) + ` ${Math.abs(reste)} ` + @json(__("œufs"));
            resteCount.classList.replace('text-emerald-400', 'text-rose-500');
        } else {
            resteCount.innerText = @json(__("Reste :")) + ` ${reste} ` + @json(__("œufs"));
            resteCount.classList.replace('text-rose-500', 'text-emerald-400');
        }
    }

    if (brutAlvDisplay) {
        const plateauxComplets = Math.floor(brutUnites / setting('general.eggs_per_tray', 30));
        const resteUnites = brutUnites % setting('general.eggs_per_tray', 30);
        brutAlvDisplay.innerText = @json(__("Cible :")) + ` ${plateauxComplets} ` + @json(__("Pl.")) + ` + ${resteUnites} ` + @json(__("Un."));
    }

    if (Math.abs(reste) < 0.1) {
        feedback.innerHTML = "✅ " + @json(__("ÉQUILIBRE PARFAIT"));
        feedback.className = "px-8 py-3 rounded-2xl text-xs font-black uppercase bg-emerald-500 text-white shadow-lg shadow-emerald-500/30 transition-all scale-110 italic";
        btn.disabled = false;
        btn.style.opacity = "1";
    } else {
        feedback.innerHTML = reste > 0 ? `⚠️ ` + @json(__("MANQUE")) + ` ${reste} ` + @json(__("ŒUFS")) : `🚫 ` + @json(__("EXCÈS")) + ` ${Math.abs(reste)} ` + @json(__("ŒUFS"));
        feedback.className = reste > 0 ? "px-8 py-3 rounded-2xl text-xs font-black uppercase bg-white text-orange-500 border-2 border-orange-200 transition-all scale-100 italic" : "px-8 py-3 rounded-2xl text-xs font-black uppercase bg-rose-500 text-white shadow-lg shadow-rose-500/30 transition-all scale-100 italic";
        btn.disabled = true;
        btn.style.opacity = "0.4";
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[type="number"]').forEach(input => {
        input.addEventListener('focus', function() { this.select(); });
    });
    updateUI();
});
</script>
</x-app-layout>