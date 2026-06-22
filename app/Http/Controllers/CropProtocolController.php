<?php

namespace App\Http\Controllers;

use App\Models\CropProtocol;
use App\Models\CropSpecies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Protocoles de traitement / itinéraires techniques (module: cultures).
 *
 * Pendant végétal de ProtocolController : CRUD du référentiel d'itinéraires
 * (protocole + étapes datées en jours après semis). Les étapes sont gérées avec
 * le protocole (remplacées intégralement à la mise à jour, cf. CropRecipe).
 */
class CropProtocolController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $protocols = CropProtocol::withCount(['items', 'cycles'])
            ->orderByDesc('is_active')->orderBy('crop_name')->orderBy('name')
            ->get();

        // Espèces du catalogue sans itinéraire technique associé.
        $coveredNames = $protocols->pluck('crop_name')->filter()->unique()->values();
        $uncoveredSpecies = CropSpecies::active()
            ->whereNotIn('name', $coveredNames)
            ->orderBy('type')->orderBy('name')
            ->get(['id', 'name', 'type']);

        return view('cultures.protocols.index', [
            'protocols'        => $protocols,
            'zones'            => CropSpecies::ZONES,
            'uncoveredSpecies' => $uncoveredSpecies,
        ]);
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.protocols.create', [
            'zones'     => CropSpecies::ZONES,
            'itemTypes' => CropProtocol::ITEM_TYPES,
            'species'   => CropSpecies::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $this->validatePayload($request);

        $protocol = DB::transaction(function () use ($validated, $request) {
            $protocol = CropProtocol::create([
                'crop_name'   => $validated['crop_name'] ?? null,
                'agro_zone'   => $validated['agro_zone'] ?? null,
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
                'source'      => $validated['source'] ?? null,
                'is_active'   => $request->boolean('is_active', true),
            ]);

            $this->syncItems($protocol, $request->input('items', []));

            return $protocol;
        });

        return redirect()->route('crop-protocols.show', $protocol)
            ->with('success', "Itinéraire « {$protocol->name} » créé.");
    }

    public function show(CropProtocol $cropProtocol)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $cropProtocol->load('items');

        return view('cultures.protocols.show', [
            'protocol' => $cropProtocol,
            'zones'    => CropSpecies::ZONES,
        ]);
    }

    public function edit(CropProtocol $cropProtocol)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $cropProtocol->load('items');

        return view('cultures.protocols.edit', [
            'protocol'  => $cropProtocol,
            'zones'     => CropSpecies::ZONES,
            'itemTypes' => CropProtocol::ITEM_TYPES,
            'species'   => CropSpecies::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, CropProtocol $cropProtocol)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($cropProtocol, $validated, $request) {
            $cropProtocol->update([
                'crop_name'   => $validated['crop_name'] ?? null,
                'agro_zone'   => $validated['agro_zone'] ?? null,
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
                'source'      => $validated['source'] ?? null,
                'is_active'   => $request->boolean('is_active', true),
            ]);

            // Remplacement intégral des étapes (delete + recreate), cf. CropRecipe.
            $cropProtocol->items()->delete();
            $this->syncItems($cropProtocol, $request->input('items', []));
        });

        return redirect()->route('crop-protocols.show', $cropProtocol)
            ->with('success', 'Itinéraire mis à jour.');
    }

    public function destroy(CropProtocol $cropProtocol)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $cropProtocol->delete();

        return redirect()->route('crop-protocols.index')->with('success', 'Itinéraire supprimé.');
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'crop_name'                => 'nullable|string|max:255',
            'agro_zone'                => 'nullable|in:' . implode(',', array_keys(CropSpecies::ZONES)),
            'name'                     => 'required|string|max:255',
            'description'              => 'nullable|string|max:2000',
            'source'                   => 'nullable|string|max:255',
            'is_active'                => 'nullable|boolean',
            'items'                    => 'nullable|array',
            'items.*.day_number'       => 'required_with:items.*.action_name|integer|min:0',
            'items.*.stage'            => 'nullable|string|max:255',
            'items.*.action_name'      => 'required_with:items.*.day_number|string|max:255',
            'items.*.type'             => 'required_with:items.*.action_name|in:' . implode(',', array_keys(CropProtocol::ITEM_TYPES)),
            'items.*.product_suggested' => 'nullable|string|max:255',
            'items.*.dose'             => 'nullable|string|max:255',
            'items.*.method'           => 'nullable|string|max:255',
            'items.*.notes'            => 'nullable|string|max:1000',
        ]);
    }

    private function syncItems(CropProtocol $protocol, array $items): void
    {
        foreach ($items as $item) {
            if (empty($item['action_name']) || ! isset($item['day_number']) || $item['day_number'] === '') {
                continue;
            }
            $protocol->items()->create([
                'day_number'        => (int) $item['day_number'],
                'stage'             => $item['stage'] ?? null,
                'action_name'       => $item['action_name'],
                'type'              => $item['type'] ?? 'autre',
                'product_suggested' => $item['product_suggested'] ?? null,
                'dose'              => $item['dose'] ?? null,
                'method'            => $item['method'] ?? null,
                'notes'             => $item['notes'] ?? null,
            ]);
        }
    }
}
