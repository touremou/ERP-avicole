<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\CropTransformation;
use App\Models\Dispatch;
use App\Models\EggProduction;
use App\Models\Harvest;
use App\Models\MillProduction;
use App\Models\Stock;
use App\Services\QrCodeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TraceabilityController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────
    // LOT D'ÉLEVAGE (page riche dédiée + étiquettes lot & œufs)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Page PUBLIQUE de traçabilité d'un lot d'élevage (scannée via le QR).
     *
     * Volontairement sans authentification : un client, un inspecteur ou un
     * distributeur doit pouvoir vérifier l'origine en scannant le QR. On
     * n'expose QUE des informations d'origine — aucune donnée financière.
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
        $symbology = setting('etiquettes.symbology', 'qr');
        $barcode = \App\Services\BarcodeService::render($batch->code, setting('etiquettes.barcode_format', 'code128'));

        return view('traceability.batch-label', compact('batch', 'qr', 'traceUrl', 'symbology', 'barcode'));
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
        $symbology = setting('etiquettes.symbology', 'qr');
        $barcode = \App\Services\BarcodeService::render($batch?->code ?? 'OEUFS', setting('etiquettes.barcode_format', 'code128'));

        return view('traceability.egg-label', compact('eggProduction', 'batch', 'qr', 'traceUrl', 'symbology', 'barcode'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // PROVENDERIE — ORDRE DE PRODUCTION D'ALIMENT
    // ──────────────────────────────────────────────────────────────────────

    public function mill(string $number): View
    {
        $op = MillProduction::withoutFarm()
            ->with(['formula', 'machines', 'farm'])
            ->where('batch_number', $number)
            ->first();

        if (! $op) {
            throw new NotFoundHttpException("Ordre de production introuvable.");
        }

        return $this->renderDocument([
            'title'   => 'Aliment composé',
            'code'    => $op->batch_number,
            'status'  => $op->status,
            'accent'  => 'lime',
            'farm'    => $op->farm?->name,
            'rows'    => array_filter([
                ['Formule', $op->formula?->name],
                ['Quantité produite', number_format((float) $op->quantity_produced, 0, ',', ' ') . ' kg (' . $op->nb_bags . ' sacs)'],
                ['Atelier', $op->machines->pluck('name')->join(', ') ?: null],
                ['Date de production', optional($op->finished_at ?? $op->created_at)->format('d/m/Y')],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    public function millLabel($id): View
    {
        if (Gate::denies('provenderie.L')) {
            abort(403);
        }

        $op = MillProduction::with(['formula', 'farm'])->findOrFail($id);

        return $this->renderLabel([
            'title'    => 'Aliment composé',
            'code'     => $op->batch_number,
            'accent'   => 'lime',
            'farm'     => $op->farm?->name,
            'traceUrl' => route('trace.mill', $op->batch_number),
            'rows'     => array_filter([
                ['Formule', $op->formula?->name],
                ['Quantité', number_format((float) $op->quantity_produced, 0, ',', ' ') . ' kg'],
                ['Produit le', optional($op->finished_at ?? $op->created_at)->format('d/m/Y')],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRODUCTION VÉGÉTALE — TRANSFORMATION (gari, jus, farine…)
    // ──────────────────────────────────────────────────────────────────────

    public function crop(string $number): View
    {
        $t = CropTransformation::withoutFarm()
            ->with(['cropCycle', 'farm'])
            ->where('batch_number', $number)
            ->first();

        if (! $t) {
            throw new NotFoundHttpException("Transformation introuvable.");
        }

        return $this->renderDocument([
            'title'   => $t->output_product,
            'code'    => $t->batch_number,
            'status'  => $t->status === 'termine' ? 'Terminé' : 'En cours',
            'accent'  => 'green',
            'farm'    => $t->farm?->name,
            'rows'    => array_filter([
                ['Matière première', $t->input_product],
                ['Type de transformation', $t->transformation_type],
                ['Quantité produite', filled($t->output_quantity) ? (rtrim(rtrim(number_format((float) $t->output_quantity, 2, '.', ''), '0'), '.') . ' ' . $t->output_unit) : null],
                ['Date de production', optional($t->production_date)->format('d/m/Y')],
                ['À consommer avant', optional($t->expiry_date)->format('d/m/Y')],
                ['Culture d\'origine', $t->cropCycle?->crop_name],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    public function cropLabel(CropTransformation $cropTransformation): View
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        $cropTransformation->loadMissing('farm');

        return $this->renderLabel([
            'title'    => $cropTransformation->output_product,
            'code'     => $cropTransformation->batch_number,
            'accent'   => 'green',
            'farm'     => $cropTransformation->farm?->name,
            'traceUrl' => route('trace.crop', $cropTransformation->batch_number),
            'rows'     => array_filter([
                ['Issu de', $cropTransformation->input_product],
                ['Produit le', optional($cropTransformation->production_date)->format('d/m/Y')],
                ['À consommer avant', optional($cropTransformation->expiry_date)->format('d/m/Y')],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // LOGISTIQUE — EXPÉDITION
    // ──────────────────────────────────────────────────────────────────────

    public function dispatch(string $number): View
    {
        $d = Dispatch::withoutFarm()
            ->with(['items', 'farm'])
            ->where('dispatch_number', $number)
            ->first();

        if (! $d) {
            throw new NotFoundHttpException("Expédition introuvable.");
        }

        $contenu = $d->items
            ->map(fn ($i) => trim($i->product_name . ' — ' . rtrim(rtrim(number_format((float) $i->quantity_dispatched, 2, '.', ''), '0'), '.') . ' ' . $i->unit))
            ->join(' · ');

        return $this->renderDocument([
            'title'   => 'Expédition',
            'code'    => $d->dispatch_number,
            'status'  => $d->status,
            'accent'  => 'indigo',
            'farm'    => $d->farm?->name,
            'rows'    => array_filter([
                ['Destination', $d->destination],
                ['Chauffeur', trim($d->driver_name . ($d->driver_phone ? " ({$d->driver_phone})" : ''))],
                ['Véhicule', $d->vehicle_plate],
                ['Date d\'expédition', optional($d->dispatch_date)->format('d/m/Y') . ($d->dispatch_time ? ' ' . substr((string) $d->dispatch_time, 0, 5) : '')],
                ['Contenu', $contenu ?: null],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    public function dispatchLabel(Dispatch $dispatch): View
    {
        if (Gate::denies('logistique.L')) {
            abort(403);
        }

        $dispatch->loadMissing('farm');

        return $this->renderLabel([
            'title'    => 'Expédition',
            'code'     => $dispatch->dispatch_number,
            'accent'   => 'indigo',
            'farm'     => $dispatch->farm?->name,
            'traceUrl' => route('trace.dispatch', $dispatch->dispatch_number),
            'rows'     => array_filter([
                ['Destination', $dispatch->destination],
                ['Chauffeur', $dispatch->driver_name],
                ['Expédié le', optional($dispatch->dispatch_date)->format('d/m/Y')],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRODUCTION VÉGÉTALE — RÉCOLTE (produit frais quittant la ferme)
    // ──────────────────────────────────────────────────────────────────────

    public function harvest(string $uuid): View
    {
        $h = Harvest::withoutFarm()
            ->with(['cropCycle.plot', 'farm'])
            ->where('uuid', $uuid)
            ->first();

        if (! $h) {
            throw new NotFoundHttpException("Récolte introuvable.");
        }

        return $this->renderDocument([
            'title'   => 'Récolte fraîche',
            'code'    => $h->cropCycle?->crop_name ?? 'Récolte',
            'status'  => $h->quality ? ucfirst($h->quality) : null,
            'accent'  => 'green',
            'farm'    => $h->farm?->name,
            'rows'    => array_filter([
                ['Date de récolte', optional($h->harvest_date)->format('d/m/Y')],
                ['Quantité', filled($h->quantity) ? (rtrim(rtrim(number_format((float) $h->quantity, 2, '.', ''), '0'), '.') . ' ' . $h->unit) : null],
                ['Variété', $h->cropCycle?->variety],
                ['Parcelle', $h->cropCycle?->plot?->name],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    public function harvestLabel(\App\Models\CropCycle $cropCycle, Harvest $harvest): View
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        $harvest->loadMissing(['cropCycle', 'farm']);

        return $this->renderLabel([
            'title'    => 'Récolte — ' . ($harvest->cropCycle?->crop_name ?? ''),
            'code'     => $harvest->cropCycle?->crop_name ?? 'Récolte',
            'accent'   => 'green',
            'farm'     => $harvest->farm?->name,
            'traceUrl' => route('trace.harvest', $harvest->uuid),
            'rows'     => array_filter([
                ['Récoltée le', optional($harvest->harvest_date)->format('d/m/Y')],
                ['Quantité', filled($harvest->quantity) ? (rtrim(rtrim(number_format((float) $harvest->quantity, 2, '.', ''), '0'), '.') . ' ' . $harvest->unit) : null],
                ['Qualité', $harvest->quality ? ucfirst($harvest->quality) : null],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // STOCK — ÉTIQUETTE DE RAYON / BAC (usage INTERNE)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Étiquette d'identification d'un article de stock. Contrairement aux
     * autres QR (publics), celui-ci pointe vers la fiche INTERNE de l'article :
     * les niveaux d'inventaire sont une donnée sensible qui ne doit pas être
     * exposée publiquement. Le magasinier connecté scanne pour ouvrir l'article.
     */
    public function stockLabel($id): View
    {
        if (Gate::denies('logistique.L')) {
            abort(403);
        }

        $stock = Stock::with('farm')->findOrFail($id);

        return $this->renderLabel([
            'title'    => 'Article de stock',
            'code'     => $stock->item_name,
            'accent'   => 'slate',
            'farm'     => $stock->farm?->name,
            'traceUrl' => route('stocks.show', $stock->id), // fiche interne (authentifiée)
            'rows'     => array_filter([
                ['Catégorie', ucfirst(str_replace('_', ' ', (string) $stock->category))],
                ['Unité', $stock->unit],
            ], fn ($r) => filled($r[1])),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // RENDU GÉNÉRIQUE
    // ──────────────────────────────────────────────────────────────────────

    private function renderDocument(array $data): View
    {
        return view('traceability.document', $data);
    }

    private function renderLabel(array $data): View
    {
        $data['qr'] = QrCodeService::dataUri($data['traceUrl']);
        $data['symbology'] = setting('etiquettes.symbology', 'qr');
        $data['barcode'] = \App\Services\BarcodeService::render((string) ($data['code'] ?? ''), setting('etiquettes.barcode_format', 'code128'));

        return view('traceability.document-label', $data);
    }
}
