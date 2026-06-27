<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SalePriceList;
use App\Models\SalePriceListItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SalePriceListController extends Controller
{
    /** Types de produits tarifables (alignés sur le sélecteur du formulaire de vente). */
    public const PRODUCT_TYPES = [
        'oeufs'            => 'Œufs',
        'volaille_vivante' => 'Volaille vivante',
        'volaille_abattue' => 'Volaille abattue',
        'carcasse'         => 'Carcasse',
        'lait'             => 'Lait',
        'fumier'           => 'Fumier',
        'produits_finis'   => 'Produits finis',
        'autre'            => 'Autre',
    ];

    /** Écran d'administration des tarifs. */
    public function index()
    {
        if (Gate::denies('commerce.M')) {
            return redirect()->route('dashboard')->with('error', 'Gestion des tarifs réservée au commerce.');
        }

        $priceLists = SalePriceList::with('items')->orderByDesc('is_default')->orderBy('name')->get();

        return view('sales.price-lists', [
            'priceLists'   => $priceLists,
            'productTypes' => self::PRODUCT_TYPES,
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('commerce.M')) return back()->with('error', 'Non autorisé.');

        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'is_default' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($data, $request) {
            if ($request->boolean('is_default')) {
                SalePriceList::where('is_default', true)->update(['is_default' => false]);
            }
            SalePriceList::create([
                'name'       => $data['name'],
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        return back()->with('success', "Tarif « {$data['name']} » créé.");
    }

    /** Enregistre les prix par type de produit d'un tarif. */
    public function updateItems(Request $request, SalePriceList $priceList)
    {
        if (Gate::denies('commerce.M')) return back()->with('error', 'Non autorisé.');

        $prices = (array) $request->input('prices', []);

        DB::transaction(function () use ($prices, $priceList) {
            foreach ($prices as $type => $value) {
                if (! array_key_exists($type, self::PRODUCT_TYPES)) continue;

                if ($value === null || $value === '') {
                    $priceList->items()->where('product_type', $type)->delete();
                    continue;
                }

                SalePriceListItem::updateOrCreate(
                    ['sale_price_list_id' => $priceList->id, 'product_type' => $type],
                    ['unit_price' => max(0, (float) $value)]
                );
            }
        });

        return back()->with('success', "Tarifs de « {$priceList->name} » mis à jour.");
    }

    /**
     * Endpoint JSON appelé par le formulaire de vente : prix suggéré pour un
     * client et un type de produit (le tarif du client, sinon le tarif défaut).
     */
    public function suggest(Request $request)
    {
        if (Gate::denies('commerce.L')) abort(403);

        $data = $request->validate([
            'client_id'    => 'nullable|exists:clients,id',
            'product_type' => 'required|string',
        ]);

        $client = $data['client_id'] ? Client::find($data['client_id']) : null;
        $price  = SalePriceList::suggestedPrice($client, $data['product_type']);

        return response()->json(['price' => $price]);
    }
}
