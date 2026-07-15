{{--
    Modale de DÉCLARATION D'ANOMALIE SANITAIRE — partagée par toutes les pages.

    Inclusion :
      - liste de lots  : @include('health.partials.declare-incident', ['batches' => $batches])
      - lot verrouillé : @include('health.partials.declare-incident', ['fixedBatch' => $batch])

    Déclencheur (n'importe où sur la page) :
      <button @click="$dispatch('open-pathology-modal')">…</button>

    Industrialisation (audit UX 2026-07-03) :
      - symptômes en CHECKLIST standardisée (+ détails libres) → diagnostics
        vétérinaires comparables d'un incident à l'autre ;
      - gravité PRÉ-SUGGÉRÉE d'après la mortalité rapportée à l'effectif vivant
        (modifiable — la suggestion s'arrête dès que l'agent choisit lui-même) ;
      - option « quarantaine immédiate » (elevage.M) : le lot est gelé dès la
        déclaration (vente/mutation/collecte), sans attendre le vétérinaire.
--}}
@php
    $fixedBatch = $fixedBatch ?? null;

    // Taxonomie standard des symptômes (élevage GN — volailles + ruminants).
    $symptomTags = [
        __('Mortalité brutale'),
        __('Prostration / abattement'),
        __('Diarrhée blanche'),
        __('Diarrhée verdâtre'),
        __('Diarrhée sanglante'),
        __('Râles / difficultés respiratoires'),
        __('Jetage / écoulement nasal'),
        __('Symptômes nerveux (torticolis, paralysie)'),
        __('Chute de ponte'),
        __('Baisse de consommation (aliment / eau)'),
        __('Boiterie'),
        __('Plumage ébouriffé / poil piqué'),
        __('Crête cyanosée / œdème'),
        __('Lésions cutanées / plaies'),
    ];
@endphp

<div x-data="{ showHealthModal: false }"
     @open-pathology-modal.window="showHealthModal = true"
     x-show="showHealthModal"
     class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 backdrop-blur-sm px-4"
     style="display: none;"
     x-transition>

    <div @click.away="showHealthModal = false" class="bg-white rounded-[3rem] shadow-2xl w-full max-w-lg overflow-hidden text-left border border-slate-100 transform transition-all max-h-[92vh] overflow-y-auto">

        <div class="bg-rose-600 p-8 text-white flex justify-between items-center relative overflow-hidden">
            <i class="fa-solid fa-virus absolute -left-4 -top-4 text-[6rem] opacity-10"></i>
            <div class="relative z-10">
                <h3 class="text-xl font-black uppercase tracking-tighter italic leading-none">{{ __("Signalement Sanitaire") }}</h3>
                <p class="text-[10px] text-rose-200 font-bold uppercase tracking-widest mt-1">
                    {{ $fixedBatch ? __("Lot") . ' ' . $fixedBatch->code : __("Alerte Vétérinaire & Autopsie") }}
                </p>
            </div>
            <button @click="showHealthModal = false" class="text-white hover:text-rose-200 relative z-10 bg-transparent border-none cursor-pointer"><i class="fa-solid fa-xmark text-2xl"></i></button>
        </div>

        <form action="{{ route('health.incidents.store') }}" method="POST" enctype="multipart/form-data" class="p-8 space-y-6" id="incident-declare-form">
            @csrf
            {{-- Lien d'origine : pointage journalier ayant révélé l'anomalie (traçabilité). --}}
            @if(request('daily_check_id'))
                <input type="hidden" name="daily_check_id" value="{{ request('daily_check_id') }}">
            @endif

            @if($fixedBatch)
                <input type="hidden" name="batch_id" value="{{ $fixedBatch->id }}">
            @else
                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Lot concerné *") }}</label>
                    <select name="batch_id" required id="incident-batch-select" onchange="incidentSuggestSeverity()"
                            class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs uppercase shadow-inner cursor-pointer text-slate-700">
                        <option value="">{{ __("Sélectionner un lot en cours...") }}</option>
                        @foreach($batches as $incidentBatch)
                            <option value="{{ $incidentBatch->id }}"
                                    data-qty="{{ (int) $incidentBatch->current_quantity }}"
                                    {{ request('batch_id') == $incidentBatch->id ? 'selected' : '' }}>
                                {{ __("LOT #") }}{{ $incidentBatch->code }} ({{ __("Bât:") }} {{ $incidentBatch->building->name ?? __("N/A") }})
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Cadavres *") }}</label>
                    <input type="number" name="mortality_count" required min="1" placeholder="{{ __('Nb.') }}"
                           id="incident-mortality" oninput="incidentSuggestSeverity()"
                           class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs text-rose-600 shadow-inner focus:ring-2 focus:ring-rose-500 text-center">
                </div>
                <div>
                    <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">
                        {{ __("Gravité") }} <span id="incident-severity-hint" class="text-rose-400 normal-case hidden">({{ __("suggérée") }})</span>
                    </label>
                    <select name="severity" id="incident-severity" onchange="this.dataset.touched = '1'"
                            class="w-full bg-slate-50 border-none rounded-xl p-4 font-black text-xs uppercase shadow-inner cursor-pointer text-slate-700 focus:ring-2 focus:ring-rose-500">
                        <option value="mineur">{{ __("Mineur") }}</option>
                        <option value="modere" selected>{{ __("Modéré") }}</option>
                        <option value="critique">{{ __("Critique") }}</option>
                    </select>
                </div>
            </div>

            {{-- Checklist de symptômes standardisée (diagnostics comparables) --}}
            <div>
                <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-2 italic">{{ __("Symptômes observés *") }}</label>
                <div class="grid grid-cols-2 gap-1.5 max-h-44 overflow-y-auto pr-1">
                    @foreach($symptomTags as $tag)
                    <label class="flex items-center gap-2 px-3 py-2 bg-slate-50 rounded-lg cursor-pointer hover:bg-rose-50 transition-colors group">
                        <input type="checkbox" name="symptom_tags[]" value="{{ $tag }}"
                               class="rounded border-slate-300 text-rose-600 focus:ring-rose-500 shrink-0">
                        <span class="text-[9px] font-black uppercase text-slate-500 group-hover:text-rose-600 leading-tight">{{ $tag }}</span>
                    </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Détails complémentaires") }}</label>
                <textarea name="symptoms" rows="2" placeholder="{{ __('Précisions : localisation, évolution, nombre de sujets atteints...') }}"
                          class="w-full bg-slate-50 border-none rounded-xl p-4 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-rose-500 shadow-inner"></textarea>
            </div>

            <div>
                <label class="text-[9px] uppercase text-slate-400 block tracking-widest font-black mb-1 italic">{{ __("Photo Autopsie") }}</label>
                <input type="file" name="photo" accept="image/jpeg, image/png" capture="environment"
                       class="w-full bg-slate-50 border-none rounded-xl p-3 text-[10px] shadow-inner file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:font-black file:bg-rose-100 file:text-rose-700 hover:file:bg-rose-200 cursor-pointer">
            </div>

            @can('elevage.M')
            {{-- Biosécurité : geler le lot dès la déclaration, sans attendre le
                 diagnostic. Vente, mutation et collecte sont alors refusées. --}}
            <label class="flex items-start gap-3 p-4 bg-rose-50 border border-rose-200 rounded-2xl cursor-pointer">
                <input type="checkbox" name="quarantine_now" value="1"
                       class="mt-0.5 rounded border-rose-300 text-rose-600 focus:ring-rose-500 shrink-0">
                <span class="leading-tight">
                    <span class="block text-[10px] font-black uppercase tracking-widest text-rose-700 italic">{{ __("Placer le lot en quarantaine immédiatement") }}</span>
                    <span class="block text-[9px] font-bold text-rose-400 mt-1 italic">{{ __("Vente, mutation et collecte suspendues jusqu'à la levée par le circuit santé.") }}</span>
                </span>
            </label>
            @endcan

            <div class="pt-2">
                <button type="submit" class="w-full bg-slate-900 text-white py-5 rounded-xl font-black uppercase tracking-widest transition-all hover:bg-rose-600 text-[10px] shadow-lg flex items-center justify-center gap-2 border-none cursor-pointer">
                    <i class="fa-solid fa-paper-plane"></i> {{ __("Transmettre au Vétérinaire") }}
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Gravité pré-suggérée : mortalité déclarée rapportée à l'effectif vivant.
    // Seuils élevage : < 1 % mineur, 1–5 % modéré, > 5 % critique. La suggestion
    // s'efface dès que l'agent choisit lui-même (data-touched).
    (function () {
        const FIXED_QTY = {{ $fixedBatch ? (int) $fixedBatch->current_quantity : 'null' }};

        window.incidentSuggestSeverity = function () {
            const severity = document.getElementById('incident-severity');
            if (!severity || severity.dataset.touched === '1') return;

            let qty = FIXED_QTY;
            if (qty === null) {
                const select = document.getElementById('incident-batch-select');
                const opt = select && select.selectedOptions[0];
                qty = opt ? parseInt(opt.dataset.qty || '0', 10) : 0;
            }

            const morts = parseInt(document.getElementById('incident-mortality')?.value || '0', 10);
            if (!qty || qty <= 0 || morts <= 0) return;

            const pct = (morts / qty) * 100;
            severity.value = pct > 5 ? 'critique' : (pct >= 1 ? 'modere' : 'mineur');
            document.getElementById('incident-severity-hint')?.classList.remove('hidden');
        };
    })();
</script>
