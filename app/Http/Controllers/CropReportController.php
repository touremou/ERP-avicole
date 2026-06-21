<?php

namespace App\Http\Controllers;

use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\CropTransformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CropReportController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        return view('cultures.reports.index');
    }

    // ── RENDEMENTS ──────────────────────────────────────────────────────────

    public function yield(Request $request): View
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        return view('cultures.reports.yield', $this->buildYieldStats($request));
    }

    public function yieldPdf(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        $pdf = \Pdf::loadView('cultures.reports.pdf.yield', $this->buildYieldStats($request))
            ->setPaper('a4', 'landscape');

        return $pdf->download('rendements-' . $request->get('year', now()->year) . '.pdf');
    }

    private function buildYieldStats(Request $request): array
    {
        $year     = (int) $request->get('year', now()->year);
        $cropName = $request->get('crop_name');

        $query = CropCycle::with(['harvests', 'plot', 'campaign'])
            ->whereYear('planting_date', $year)
            ->whereIn('status', [CropCycle::STATUS_RECOLTE, CropCycle::STATUS_TERMINE]);

        if ($cropName) {
            $query->where('crop_name', $cropName);
        }

        $cycles = $query->orderBy('planting_date')->get();

        $totalHarvested = $cycles->sum(fn ($c) => $c->total_harvested);
        $totalArea      = (float) $cycles->sum('area_used_ha');
        $avgYieldPerHa  = $totalArea > 0 ? round($totalHarvested / $totalArea, 2) : 0;

        $byCrop = $cycles->groupBy('crop_name')->map(function ($group, $name) {
            $harvested = $group->sum(fn ($c) => $c->total_harvested);
            $area      = (float) $group->sum('area_used_ha');

            return [
                'name'         => $name,
                'cycles_count' => $group->count(),
                'total_ha'     => round($area, 2),
                'total_kg'     => round($harvested, 1),
                'yield_per_ha' => $area > 0 ? round($harvested / $area, 2) : 0,
                'net_margin'   => $group->sum(fn ($c) => $c->net_margin),
            ];
        })->sortByDesc('total_kg')->values();

        $years     = $this->cycleYears();
        $cropNames = CropCycle::distinct()->orderBy('crop_name')->pluck('crop_name');

        return compact('year', 'cropName', 'cycles', 'totalHarvested', 'totalArea', 'avgYieldPerHa', 'byCrop', 'years', 'cropNames');
    }

    // ── INTRANTS ─────────────────────────────────────────────────────────────

    public function inputs(Request $request): View
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        return view('cultures.reports.inputs', $this->buildInputsStats($request));
    }

    public function inputsPdf(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        $pdf = \Pdf::loadView('cultures.reports.pdf.inputs', $this->buildInputsStats($request))
            ->setPaper('a4', 'portrait');

        return $pdf->download('intrants-' . $request->get('year', now()->year) . '.pdf');
    }

    private function buildInputsStats(Request $request): array
    {
        $year = (int) $request->get('year', now()->year);

        $inputs = CropInput::with('cropCycle')
            ->whereHas('cropCycle', fn ($q) => $q->whereYear('planting_date', $year))
            ->get();

        $totalCost = (float) $inputs->sum('total_cost');

        $byType = $inputs->groupBy('type')->map(function ($group, $type) use ($totalCost) {
            $cost = (float) $group->sum('total_cost');

            return [
                'type'  => $type,
                'label' => CropInput::TYPES[$type] ?? ucfirst((string) $type),
                'count' => $group->count(),
                'cost'  => $cost,
                'pct'   => $totalCost > 0 ? round($cost / $totalCost * 100, 1) : 0,
            ];
        })->sortByDesc('cost')->values();

        $byCrop = $inputs->groupBy(fn ($i) => $i->cropCycle->crop_name ?? '—')
            ->map(fn ($group, $crop) => [
                'crop'  => $crop,
                'cost'  => (float) $group->sum('total_cost'),
                'count' => $group->count(),
            ])
            ->sortByDesc('cost')
            ->values();

        $years = $this->cycleYears();

        return compact('year', 'inputs', 'totalCost', 'byType', 'byCrop', 'years');
    }

    // ── CAMPAGNES ────────────────────────────────────────────────────────────

    public function campaigns(Request $request): View
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        return view('cultures.reports.campaigns', $this->buildCampaignsStats($request));
    }

    public function campaignsPdf(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        $pdf = \Pdf::loadView('cultures.reports.pdf.campaigns', $this->buildCampaignsStats($request))
            ->setPaper('a4', 'portrait');

        return $pdf->download('campagnes-' . $request->get('year', now()->year) . '.pdf');
    }

    private function buildCampaignsStats(Request $request): array
    {
        $year = (int) $request->get('year', now()->year);

        $campaigns = CropCampaign::with(['cycles.harvests', 'cycles.inputs'])
            ->where('year', $year)
            ->orderBy('start_date')
            ->get();

        $years = CropCampaign::selectRaw('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year');

        return compact('year', 'campaigns', 'years');
    }

    // ── TRANSFORMATIONS ───────────────────────────────────────────────────────

    public function transformations(Request $request): View
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        return view('cultures.reports.transformations', $this->buildTransformationsStats($request));
    }

    public function transformationsPdf(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            abort(403);
        }

        $pdf = \Pdf::loadView('cultures.reports.pdf.transformations', $this->buildTransformationsStats($request))
            ->setPaper('a4', 'landscape');

        return $pdf->download('transformations-' . $request->get('year', now()->year) . '.pdf');
    }

    private function buildTransformationsStats(Request $request): array
    {
        $year = (int) $request->get('year', now()->year);
        $type = $request->get('transformation_type');

        $query = CropTransformation::whereYear('production_date', $year);

        if ($type) {
            $query->where('transformation_type', $type);
        }

        $transformations = $query->orderBy('production_date')->get();

        $totalInput  = (float) $transformations->sum('input_quantity');
        $totalOutput = (float) $transformations->sum('output_quantity');
        $avgYield    = $totalInput > 0 ? round($totalOutput / $totalInput * 100, 2) : 0;
        $totalValue  = (float) $transformations->sum(fn ($t) => $t->estimated_value);
        $totalCost   = (float) $transformations->sum('production_cost');

        $byType = $transformations->groupBy('transformation_type')
            ->map(function ($group, $ttype) {
                $inp = (float) $group->sum('input_quantity');
                $out = (float) $group->sum('output_quantity');

                return [
                    'type'   => $ttype,
                    'label'  => CropTransformation::TYPES[$ttype] ?? ucfirst((string) $ttype),
                    'count'  => $group->count(),
                    'input'  => $inp,
                    'output' => $out,
                    'yield'  => $inp > 0 ? round($out / $inp * 100, 2) : 0,
                    'value'  => (float) $group->sum(fn ($t) => $t->estimated_value),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $years = CropTransformation::whereNotNull('production_date')
            ->pluck('production_date')
            ->map(fn ($d) => (int) $d->format('Y'))
            ->unique()
            ->sortDesc()
            ->values();

        $types = CropTransformation::TYPES;

        return compact('year', 'type', 'transformations', 'totalInput', 'totalOutput', 'avgYield', 'totalValue', 'totalCost', 'byType', 'years', 'types');
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function cycleYears()
    {
        return CropCycle::whereNotNull('planting_date')
            ->pluck('planting_date')
            ->map(fn ($d) => (int) $d->format('Y'))
            ->unique()
            ->sortDesc()
            ->values();
    }
}
