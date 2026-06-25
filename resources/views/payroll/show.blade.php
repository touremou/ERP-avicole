<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center text-left">
            <div class="flex items-center gap-4">
                <a href="{{ route('payroll.index') }}" class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center text-slate-400 hover:bg-slate-900 hover:text-white transition-all no-underline"><i class="fa-solid fa-arrow-left"></i></a>
                <div>
                    <h2 class="text-lg font-black text-slate-800 uppercase italic tracking-tighter leading-none">{{ $period->label }}</h2>
                    <p class="text-[9px] font-bold text-slate-400 uppercase mt-1 tracking-widest italic">
                        {{ $period->start_date->format('d/m') }} → {{ $period->end_date->format('d/m/Y') }} — {{ __(ucfirst($period->status)) }}
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                {{-- Générer = Modification (M) --}}
                @can('annuaire.M')
                    @if($period->status === 'brouillon')
                    <form method="POST" action="{{ route('payroll.generate', $period) }}">@csrf
                        <button class="bg-blue-600 text-white px-5 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-blue-700 border-none cursor-pointer shadow-lg italic"><i class="fa-solid fa-calculator mr-1"></i> {{ __("Générer les fiches") }}</button>
                    </form>
                    @endif
                @endcan
                
                {{-- Valider = Superviseur/Admin (S) (Correction du 'S' en 'annuaire.S') --}}
                @can('annuaire.S')
                    @if($period->status === 'calcule')
                    <form method="POST" action="{{ route('payroll.validate', $period) }}">@csrf
                        <button class="bg-emerald-600 text-white px-5 py-2.5 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-emerald-700 border-none cursor-pointer shadow-lg italic"><i class="fa-solid fa-check-double mr-1"></i> {{ __("Valider la période") }}</button>
                    </form>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8 italic font-bold" x-data="payrollUI()">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            @foreach(['success', 'error'] as $msg)
                @if(session($msg))
                    <div @class(['mb-6 p-4 rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg flex items-center italic',
                        'bg-emerald-500 text-white' => $msg === 'success', 'bg-red-500 text-white' => $msg === 'error'])>
                        <i class="fa-solid fa-{{ $msg === 'success' ? 'check-double' : 'circle-xmark' }} mr-3"></i> {{ session($msg) }}
                    </div>
                @endif
            @endforeach

            {{-- KPI --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">{{ __("Employés") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $kpi['total_employees'] }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-blue-500 uppercase tracking-widest">{{ __("Masse brute") }}</p>
                    <p class="text-lg font-black text-slate-900">{{ number_format($kpi['total_brut'], 0, ',', '.') }}</p>
                </div>
                <div class="bg-emerald-50 p-4 rounded-2xl border border-emerald-200 shadow-sm text-center">
                    <p class="text-[7px] font-black text-emerald-500 uppercase tracking-widest">{{ __("Net à payer") }}</p>
                    <p class="text-lg font-black text-emerald-600">{{ number_format($kpi['total_net'], 0, ',', '.') }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">{{ __("Payés") }}</p>
                    <p class="text-2xl font-black text-emerald-600">{{ $kpi['paid_count'] }}<span class="text-slate-400">/{{ $kpi['total_employees'] }}</span></p>
                </div>
            </div>

            {{-- FICHES DE PAIE --}}
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden text-left">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-[7px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                            <th class="px-5 py-3 text-left">{{ __("Employé") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Base") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Primes") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Déductions") }}</th>
                            <th class="px-3 py-3 text-right">{{ __("Net") }}</th>
                            <th class="px-3 py-3 text-center">{{ __("Jours") }}</th>
                            <th class="px-3 py-3 text-center">{{ __("Paiement") }}</th>
                            <th class="px-5 py-3 text-center">{{ __("Actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($period->payslips as $slip)
                        <tr class="hover:bg-slate-50/50 transition-all">
                            <td class="px-5 py-3">
                                <p class="text-xs font-black text-slate-900 uppercase">{{ $slip->employee->first_name }} {{ $slip->employee->last_name }}</p>
                                <p class="text-[8px] text-slate-400">{{ $slip->employee->job_title ?? '—' }}</p>
                            </td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-slate-700">{{ number_format($slip->base_salary, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-emerald-600">{{ $slip->total_primes > 0 ? '+' . number_format($slip->total_primes, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-3 text-right text-[10px] font-black text-red-500">{{ $slip->total_deductions > 0 ? '-' . number_format($slip->total_deductions, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-3 text-right text-sm font-black text-slate-900">{{ number_format($slip->net_salary, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-center text-[9px] font-black text-slate-500">
                                {{ $slip->days_worked }}{{ __("j") }}
                                @if($slip->days_absent > 0)<span class="text-red-500 ml-1">-{{ $slip->days_absent }}{{ __("abs") }}</span>@endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span @class(['text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                    'bg-emerald-100 text-emerald-600' => $slip->payment_status === 'paye',
                                    'bg-amber-100 text-amber-600' => $slip->payment_status === 'en_attente',
                                    'bg-blue-100 text-blue-600' => $slip->payment_status === 'partiel'])>
                                    {{ $slip->payment_status === 'paye' ? '✓ ' . __("Payé") : __("En attente") }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        {{-- Imprimer bon/fiche --}}
                                        <a href="{{ route('payroll.print', ['payslip' => $slip, 'type' => $slip->payment_status === 'paye' ? 'fiche' : 'bon']) }}" target="_blank"
                                            class="w-7 h-7 rounded-lg bg-slate-50 text-slate-400 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-center no-underline transition-all" title="{{ $slip->payment_status === 'paye' ? __("Fiche de paie") : __("Bon de paie") }}">
                                            <i class="fa-solid fa-print text-[9px]"></i>
                                        </a>
                                        {{-- Ajouter prime/déduction (bloqué si bulletin payé ou période soldée) --}}
                                        @can('annuaire.M')
                                            @if(! $slip->isLocked())
                                            <button @click="openLineModal({{ $slip->id }}, '{{ addslashes($slip->employee->first_name) }}')"
                                                class="w-7 h-7 rounded-lg bg-slate-50 text-slate-400 hover:bg-blue-50 hover:text-blue-600 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __("Ajouter prime/déduction") }}">
                                                <i class="fa-solid fa-plus text-[9px]"></i>
                                            </button>
                                            <button @click="openOvertimeModal({{ $slip->id }}, '{{ addslashes($slip->employee->first_name) }}')"
                                                class="w-7 h-7 rounded-lg bg-slate-50 text-slate-400 hover:bg-amber-50 hover:text-amber-600 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __("Heures supplémentaires") }}">
                                                <i class="fa-solid fa-clock text-[9px]"></i>
                                            </button>
                                            @endif
                                        @endcan

                                        {{-- Marquer payé --}}
                                        @can('annuaire.M')
                                            @if($slip->payment_status !== 'paye' && in_array($period->status, ['calcule', 'valide']))
                                            <button @click="openPayModal({{ $slip->id }}, '{{ addslashes($slip->employee->first_name) }}', {{ $slip->net_salary }}, '{{ $slip->employee->orange_money_number ?? '' }}')"
                                                class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-500 hover:bg-emerald-100 hover:text-emerald-700 flex items-center justify-center border-none cursor-pointer transition-all" title="{{ __("Payer") }}">
                                                <i class="fa-solid fa-money-bill text-[9px]"></i>
                                            </button>
                                            @endif
                                        @endcan
                                </div>
                            </td>
                        </tr>
                        {{-- Sous-lignes détail --}}
                        @if($slip->lines->count() > 0)
                        <tr class="bg-slate-50/30">
                            <td colspan="8" class="px-8 py-2">
                                <div class="flex flex-wrap gap-2">
                                    @foreach($slip->lines as $line)
                                    <span @class(['text-[8px] font-black px-2 py-1 rounded-lg inline-flex items-center gap-1',
                                        'bg-emerald-50 text-emerald-600' => $line->type === 'prime',
                                        'bg-red-50 text-red-500' => $line->type === 'deduction'])>
                                        {{ $line->type === 'prime' ? '+' : '-' }}{{ number_format($line->amount, 0, ',', '.') }} {{ $line->label }}
                                        @can('annuaire.M')
                                            @if(! $slip->isLocked())
                                            <form method="POST" action="{{ route('payroll.remove-line', $line) }}" class="inline">@csrf @method('DELETE')
                                                <button class="text-slate-300 hover:text-red-500 border-none bg-transparent cursor-pointer ml-1"><i class="fa-solid fa-xmark text-[8px]"></i></button>
                                            </form>
                                            @endif
                                        @endcan
                                    </span>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                        @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- MODAL AJOUT PRIME/DÉDUCTION --}}
        <div x-show="lineModal" x-transition class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" x-cloak>
            <div class="bg-white rounded-2xl w-full max-w-md p-8 text-left font-bold italic" @click.outside="lineModal = false">
                <h3 class="text-lg font-black text-slate-800 uppercase tracking-tighter mb-4" x-text="@json(__("Prime/Déduction")) + ' — ' + lineEmployee"></h3>
                <form :action="'/payroll/payslip/' + lineSlipId + '/line'" method="POST" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <label class="cursor-pointer"><input type="radio" name="type" value="prime" class="hidden peer" checked>
                            <div class="peer-checked:bg-emerald-50 peer-checked:border-emerald-400 bg-slate-50 border-2 border-transparent rounded-xl p-3 text-center transition-all text-[9px] font-black uppercase">✅ {{ __("Prime") }}</div></label>
                        <label class="cursor-pointer"><input type="radio" name="type" value="deduction" class="hidden peer">
                            <div class="peer-checked:bg-red-50 peer-checked:border-red-400 bg-slate-50 border-2 border-transparent rounded-xl p-3 text-center transition-all text-[9px] font-black uppercase">➖ {{ __("Déduction") }}</div></label>
                    </div>
                    <input type="text" name="label" required placeholder="{{ __("Ex: Prime performance, Avance...") }}" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                    <input type="number" name="amount" required min="1" placeholder="{{ __("Montant") }} {{ currency() }}" class="w-full bg-slate-50 border-none rounded-xl p-3 text-lg font-black shadow-inner outline-none text-center">
                    <select name="category" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none italic">
                        <option value="performance">{{ __("Performance") }}</option>
                        <option value="nuit">{{ __("Nuit / Astreinte") }}</option>
                        <option value="ferie">{{ __("Jour férié") }}</option>
                        <option value="transport">{{ __("Transport") }}</option>
                        <option value="avance">{{ __("Avance sur salaire") }}</option>
                        <option value="absence">{{ __("Absence") }}</option>
                        <option value="autre">{{ __("Autre") }}</option>
                    </select>
                    <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 border-none cursor-pointer italic">{{ __("Ajouter") }}</button>
                </form>
            </div>
        </div>

        {{-- MODAL HEURES SUPPLÉMENTAIRES --}}
        <div x-show="overtimeModal" x-transition class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" x-cloak>
            <div class="bg-white rounded-2xl w-full max-w-md p-8 text-left font-bold italic" @click.outside="overtimeModal = false">
                <h3 class="text-lg font-black text-amber-600 uppercase tracking-tighter mb-2" x-text="'⏱ ' + @json(__("Heures sup.")) + ' — ' + otEmployee"></h3>
                <p class="text-[9px] text-slate-500 mb-4">{{ __("Majoration appliquée : ×") }}{{ setting('rh.overtime_rate', 1.5) }} {{ __("(base mensuelle : 26 j × 8 h).") }}</p>
                <form :action="'/payroll/payslip/' + otSlipId + '/overtime'" method="POST" class="space-y-4">
                    @csrf
                    <input type="number" name="hours" required min="0.5" step="0.5" placeholder="{{ __("Nombre d'heures") }}" class="w-full bg-slate-50 border-none rounded-xl p-3 text-lg font-black shadow-inner outline-none text-center">
                    <button type="submit" class="w-full bg-amber-500 text-white py-4 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-amber-600 border-none cursor-pointer italic">{{ __("Ajouter les heures sup.") }}</button>
                </form>
            </div>
        </div>

        {{-- MODAL PAIEMENT --}}
        <div x-show="payModal" x-transition class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4" x-cloak>
            <div class="bg-white rounded-2xl w-full max-w-md p-8 text-left font-bold italic" @click.outside="payModal = false">
                <h3 class="text-lg font-black text-emerald-600 uppercase tracking-tighter mb-2">💰 {{ __("Paiement") }}</h3>
                <p class="text-[9px] text-slate-500 mb-4" x-text="payEmployee + ' — ' + payAmount.toLocaleString('fr-FR') + ' {{ currency() }}'"></p>
                <form :action="'/payroll/payslip/' + paySlipId + '/pay'" method="POST" class="space-y-4">
                    @csrf
                    @php
                        $methodLabels = ['especes' => '💵 ' . __("Espèces"), 'orange_money' => '📱 Orange Money', 'virement' => '🏦 ' . __("Virement bancaire")];
                        $methods = array_filter(array_map('trim', explode(',', setting('rh.payment_methods', 'especes,orange_money,virement'))));
                    @endphp
                    <select name="payment_method" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black uppercase shadow-inner outline-none italic">
                        @foreach($methods as $method)
                            <option value="{{ $method }}">{{ $methodLabels[$method] ?? ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="payment_reference" placeholder="{{ __("Réf. transaction (optionnel)") }}" class="w-full bg-slate-50 border-none rounded-xl p-3 text-xs font-black shadow-inner outline-none">
                    <div x-show="payOrangeMoney" class="p-3 bg-orange-50 rounded-xl">
                        <p class="text-[8px] font-black text-orange-600"><i class="fa-solid fa-phone mr-1"></i> Orange Money : <span x-text="payOrangeMoney"></span></p>
                    </div>
                    <button type="submit" class="w-full bg-emerald-500 text-white py-4 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 border-none cursor-pointer italic">{{ __("Confirmer le paiement") }}</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function payrollUI() {
        return {
            lineModal: false, lineSlipId: 0, lineEmployee: '',
            openLineModal(id, name) { this.lineSlipId = id; this.lineEmployee = name; this.lineModal = true; },
            overtimeModal: false, otSlipId: 0, otEmployee: '',
            openOvertimeModal(id, name) { this.otSlipId = id; this.otEmployee = name; this.overtimeModal = true; },
            payModal: false, paySlipId: 0, payEmployee: '', payAmount: 0, payOrangeMoney: '',
            openPayModal(id, name, amount, om) { this.paySlipId = id; this.payEmployee = name; this.payAmount = amount; this.payOrangeMoney = om; this.payModal = true; },
        }
    }
    </script>
</x-app-layout>
