<x-app-layout>
    <div class="max-w-3xl mx-auto py-12 px-6 italic font-bold text-left">
        <a href="{{ route('utilities.energy.sources') }}" class="text-xs text-slate-400 uppercase tracking-widest hover:text-slate-900 mb-6 inline-block"><i class="fa-solid fa-arrow-left mr-2"></i>{{ __("Retour") }}</a>

        <div class="bg-amber-50 p-10 rounded-[3rem] border border-amber-200">
            <h2 class="text-2xl font-black text-amber-700 uppercase tracking-tighter mb-8"><i class="fa-solid fa-pen mr-2"></i> {{ __("Modifier la source d'énergie") }}</h2>
            <form method="POST" action="{{ route('utilities.energy.sources.update', $source->id) }}" class="space-y-8">
                @csrf @method('PUT')

                {{-- ─── OPÉRATIONNEL ─── --}}
                <div>
                    <p class="text-[9px] font-black text-amber-600 uppercase tracking-widest mb-4">{{ __("Informations opérationnelles") }}</p>
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Nom *") }}</label>
                            <input type="text" name="name" value="{{ old('name', $source->name) }}" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Type *") }}</label>
                            <select name="type" required class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                                <option value="edg" {{ $source->type === 'edg' ? 'selected' : '' }}>{{ __("EDG (Réseau)") }}</option>
                                <option value="groupe" {{ $source->type === 'groupe' ? 'selected' : '' }}>{{ __("Groupe Électrogène") }}</option>
                                <option value="solaire" {{ $source->type === 'solaire' ? 'selected' : '' }}>{{ __("Solaire") }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Marque") }}</label>
                            <input type="text" name="brand" value="{{ old('brand', $source->brand) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Modèle") }}</label>
                            <input type="text" name="model" value="{{ old('model', $source->model) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("N° de série") }}</label>
                            <input type="text" name="serial_number" value="{{ old('serial_number', $source->serial_number) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Capacité kVA") }}</label>
                            <input type="number" step="0.1" name="capacity_kva" value="{{ old('capacity_kva', $source->capacity_kva) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Carburant") }}</label>
                            <select name="fuel_type" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                                <option value="">{{ __("N/A") }}</option>
                                <option value="gasoil" {{ $source->fuel_type === 'gasoil' ? 'selected' : '' }}>{{ __("Gasoil") }}</option>
                                <option value="essence" {{ $source->fuel_type === 'essence' ? 'selected' : '' }}>{{ __("Essence") }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Capacité Cuve (L)") }}</label>
                            <input type="number" name="fuel_tank_capacity" value="{{ old('fuel_tank_capacity', $source->fuel_tank_capacity) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Interv. Maint. (H)") }}</label>
                            <input type="number" name="maintenance_interval_hours" min="50" value="{{ old('maintenance_interval_hours', $source->maintenance_interval_hours) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Statut") }}</label>
                            <select name="status" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                                <option value="operationnel" {{ $source->status === 'operationnel' ? 'selected' : '' }}>{{ __("Opérationnel") }}</option>
                                <option value="maintenance" {{ $source->status === 'maintenance' ? 'selected' : '' }}>{{ __("Maintenance") }}</option>
                                <option value="panne" {{ $source->status === 'panne' ? 'selected' : '' }}>{{ __("Panne") }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- ─── REGISTRE D'ACTIF (CMMS) ─── --}}
                <div>
                    <p class="text-[9px] font-black text-indigo-500 uppercase tracking-widest mb-4">{{ __("Registre d'actif") }}</p>
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Date d'achat") }}</label>
                            <input type="date" name="purchase_date" value="{{ old('purchase_date', optional($source->purchase_date)->toDateString()) }}" class="w-full bg-white border-none rounded-2xl p-4 text-sm shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Prix d'achat (GNF)") }}</label>
                            <input type="number" name="purchase_price" min="0" step="1000" value="{{ old('purchase_price', $source->purchase_price) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Durée amort. (ans)") }}</label>
                            <input type="number" name="depreciation_years" min="1" max="30" value="{{ old('depreciation_years', $source->depreciation_years) }}" class="w-full bg-white border-none rounded-2xl p-4 shadow-sm outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Fin de garantie") }}</label>
                            <input type="date" name="warranty_expiry" value="{{ old('warranty_expiry', optional($source->warranty_expiry)->toDateString()) }}" class="w-full bg-white border-none rounded-2xl p-4 text-sm shadow-sm outline-none">
                        </div>
                        <div class="col-span-2">
                            <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Réf. contrat de service") }}</label>
                            <input type="text" name="service_contract_ref" value="{{ old('service_contract_ref', $source->service_contract_ref) }}" class="w-full bg-white border-none rounded-2xl p-4 text-sm shadow-sm outline-none">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-[10px] uppercase text-slate-400 ml-2">{{ __("Notes") }}</label>
                    <textarea name="notes" rows="2" class="w-full bg-white border-none rounded-2xl p-4 text-sm shadow-sm outline-none">{{ old('notes', $source->notes) }}</textarea>
                </div>

                <button type="submit" class="w-full bg-amber-500 text-white py-4 rounded-2xl font-black uppercase hover:bg-amber-600 shadow-lg">{{ __("Enregistrer les modifications") }}</button>
            </form>
        </div>
    </div>
</x-app-layout>
