<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$employee->first_name . ' ' . $employee->last_name" :subtitle="__('Historique Paie & Congés')" icon="fa-money-check-dollar" accent="blue" :back="route('employees.show', $employee)" />
    </x-slot>

    <div class="py-8 italic font-bold">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            {{-- KPI CARRIÈRE --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-slate-400 uppercase tracking-widest">{{ __("Mois payés") }}</p>
                    <p class="text-2xl font-black text-slate-900">{{ $totals['months_paid'] }}</p>
                </div>
                <div class="bg-emerald-50 p-4 rounded-2xl border border-emerald-200 shadow-sm text-center">
                    <p class="text-[7px] font-black text-emerald-500 uppercase tracking-widest">{{ __("Total perçu") }}</p>
                    <p class="text-lg font-black text-emerald-600">{{ number_format($totals['total_earned'], 0, ',', '.') }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-blue-500 uppercase tracking-widest">{{ __("Primes cumulées") }}</p>
                    <p class="text-lg font-black text-blue-600">{{ number_format($totals['total_primes'], 0, ',', '.') }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-red-500 uppercase tracking-widest">{{ __("Déductions") }}</p>
                    <p class="text-lg font-black text-red-500">{{ number_format($totals['total_deductions'], 0, ',', '.') }}</p>
                </div>
                <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm text-center">
                    <p class="text-[7px] font-black text-amber-500 uppercase tracking-widest">{{ __("Congés utilisés") }}</p>
                    <p class="text-2xl font-black text-amber-600">{{ $totals['leave_days_used'] }}{{ __("j") }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- FICHES DE PAIE --}}
                <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden text-left">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500 flex items-center gap-2"><i class="fa-solid fa-money-bill-wave text-blue-500"></i> {{ __("Fiches de paie") }}</h3>
                    </div>
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="text-[7px] font-black text-slate-400 uppercase tracking-widest bg-slate-50/50 border-b border-slate-100">
                                <th class="px-5 py-3 text-left">{{ __("Période") }}</th>
                                <th class="px-3 py-3 text-right">{{ __("Base") }}</th>
                                <th class="px-3 py-3 text-right">{{ __("Net") }}</th>
                                <th class="px-3 py-3 text-center">{{ __("Statut") }}</th>
                                <th class="px-5 py-3 text-center">{{ __("Actions") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($payslips as $slip)
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="px-5 py-3">
                                    <p class="text-xs font-black text-slate-900 uppercase">{{ $slip->period->label }}</p>
                                    <p class="text-[8px] text-slate-400">{{ $slip->days_worked }}{{ __("j travaillés") }}</p>
                                </td>
                                <td class="px-3 py-3 text-right text-[10px] font-black text-slate-600">{{ number_format($slip->base_salary, 0, ',', '.') }}</td>
                                <td class="px-3 py-3 text-right text-sm font-black text-slate-900">{{ number_format($slip->net_salary, 0, ',', '.') }}</td>
                                <td class="px-3 py-3 text-center">
                                    <span @class(['text-[8px] font-black uppercase px-2 py-1 rounded-full',
                                        'bg-emerald-100 text-emerald-600' => $slip->payment_status === 'paye',
                                        'bg-amber-100 text-amber-600' => $slip->payment_status !== 'paye'])>
                                        {{ $slip->payment_status === 'paye' ? '✓ '.__("Payé") : __("En attente") }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    @can('rh.M')
                                    <div class="flex items-center justify-center gap-1.5">
                                        {{-- Bon de paie (avant paiement) --}}
                                        @if($slip->payment_status !== 'paye')
                                        <a href="{{ route('payroll.print', ['payslip' => $slip, 'type' => 'bon']) }}" target="_blank"
                                           class="w-7 h-7 rounded-lg bg-amber-50 text-amber-500 hover:bg-amber-100 flex items-center justify-center no-underline transition-all" title="{{ __("Bon de paie (comptable)") }}"
                                            <i class="fa-solid fa-file-invoice text-[10px]"></i>
                                        </a>
                                        @endif
                                        {{-- Fiche de paie (après paiement) --}}
                                        <a href="{{ route('payroll.print', ['payslip' => $slip, 'type' => 'fiche']) }}" target="_blank"
                                           class="w-7 h-7 rounded-lg bg-blue-50 text-blue-500 hover:bg-blue-100 flex items-center justify-center no-underline transition-all" title="{{ __("Fiche de paie (imprimer)") }}"
                                            <i class="fa-solid fa-print text-[10px]"></i>
                                        </a>
                                    </div>
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="px-8 py-10 text-center text-slate-300 text-[9px] uppercase italic tracking-widest">{{ __("Aucune fiche") }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="px-5 py-3">{{ $payslips->links() }}</div>
                </div>

                {{-- CONGÉS --}}
                <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden text-left">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-100">
                        <h3 class="text-[10px] font-black uppercase tracking-widest text-slate-500 flex items-center gap-2"><i class="fa-solid fa-calendar-xmark text-amber-500"></i> {{ __("Congés") }}</h3>
                    </div>
                    <div class="divide-y divide-slate-50">
                        @forelse($leaves as $l)
                        <div class="px-5 py-3">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-[10px] font-black text-slate-800 uppercase">{{ $l->type_label }}</p>
                                    <p class="text-[8px] text-slate-400">{{ $l->start_date->format('d/m') }} → {{ $l->end_date->format('d/m/Y') }}</p>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-black text-slate-800">{{ $l->days_count }}{{ __("j") }}</span>
                                    <span @class(['block text-[7px] font-black uppercase px-2 py-0.5 rounded-full mt-1',
                                        'bg-emerald-100 text-emerald-600' => $l->status === 'termine',
                                        'bg-blue-100 text-blue-600' => $l->status === 'en_cours',
                                        'bg-amber-100 text-amber-600' => $l->status === 'approuve',
                                        'bg-slate-100 text-slate-500' => $l->status === 'demande'])>{{ $l->status }}</span>
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="px-5 py-8 text-center text-slate-300 text-[9px] uppercase italic tracking-widest">Aucun congé</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
