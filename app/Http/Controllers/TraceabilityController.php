<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\EggProduction;
use App\Services\QrCodeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TraceabilityController extends Controller
{
    /**
     * Page PUBLIQUE de traçabilité d'un lot (scannée via le QR de l'étiquette).
     *
     * Volontairement sans authentification : un client, un inspecteur ou un
     * distributeur doit pouvoir vérifier l'origine d'un lot ou d'un carton
     * d'œufs en scannant le QR. On n'expose QUE des informations d'origine
     * (espèce, bâtiment, dates, ferme) — aucune donnée financière.
     *
     * La recherche se fait par code métier, hors scope ferme (le scan ne porte
     * pas de session) ; le code de lot est unique.
     */
    public function batch(string $code): View
    {
        $batch = Batch::withoutGlobalScopes()
            ->with(['species', 'productionType', 'building', 'provider', 'farm'])
            ->where('code', $code)
            ->first();

        if (! $batch) {
            throw new NotFoundHttpException("Lot introuvable.");
        }

        return view('traceability.batch', compact('batch'));
    }

    /**
     * Étiquette imprimable d'un lot (QR → page de traçabilité publique).
     */
    public function batchLabel(Batch $batch): View
    {
        if (Gate::denies('elevage.L')) {
            abort(403);
        }

        $batch->loadMissing(['species', 'productionType', 'building']);

        $traceUrl = route('trace.batch', $batch->code);
        $qr = QrCodeService::dataUri($traceUrl);

        return view('traceability.batch-label', compact('batch', 'qr', 'traceUrl'));
    }

    /**
     * Étiquette imprimable d'une collecte d'œufs (carton / plateau).
     * Le QR pointe vers la traçabilité du lot d'origine.
     */
    public function eggLabel(EggProduction $eggProduction): View
    {
        if (Gate::denies('production.L')) {
            abort(403);
        }

        $eggProduction->loadMissing(['batch.species', 'batch.building', 'batch.farm']);

        $batch = $eggProduction->batch;
        $traceUrl = $batch ? route('trace.batch', $batch->code) : url('/');
        $qr = QrCodeService::dataUri($traceUrl);

        return view('traceability.egg-label', compact('eggProduction', 'batch', 'qr', 'traceUrl'));
    }
}
