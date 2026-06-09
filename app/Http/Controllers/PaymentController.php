<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Sale;
use App\Http\Requests\Sale\StorePaymentRequest;
use App\Actions\Sale\RecordPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('commerce.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $query = Payment::with(['sale.client', 'receiver']);

        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }

        $payments = $query->latest('payment_date')->paginate((int) setting('general.items_per_page', 20));

        // Caisse du jour
        $todayPayments = Payment::whereDate('payment_date', today());
        $stats = [
            'today_total'    => $todayPayments->sum('amount'),
            'today_cash'     => (clone $todayPayments)->where('method', 'especes')->sum('amount'),
            'today_om'       => (clone $todayPayments)->where('method', 'orange_money')->sum('amount'),
            'today_count'    => $todayPayments->count(),
        ];

        return view('payments.index', compact('payments', 'stats'));
    }

    public function store(StorePaymentRequest $request, RecordPayment $action)
    {
        $sale = Sale::findOrFail($request->sale_id);

        try {
            $payment = $action->execute($sale, $request->validated());

            return back()->with('success',
                "Paiement de " . number_format($payment->amount) . " GNF enregistré sur {$sale->reference}."
            );
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
