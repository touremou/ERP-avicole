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
    /**
     * Types de produits tarifables — SOURCE UNIQUE partagée avec le formulaire
     * de vente (App\Models\SaleItem::SELLABLE_TYPE_LABELS), pour que le tarif de
     * repli par catégorie corresponde exactement aux types réellement vendables.
     */
    public const PRODUCT_TYPES = \App\Models\SaleItem::SELLABLE_TYPE_LABELS;

    /** Écran d'administration des tarifs. */
    public function index()
    {
        if (Gate::denies('commerce.M')) {
            return redirect()->route('dashboard')->with('error', 'Gestion des tarifs réservée au commerce.');
        }

        $priceLists = SalePriceList::with('items')->orderByDesc('is_default')->orderBy('name')->get();
        $products   = \App\Models\Product::active()->orderBy('name')->get(['id', 'name', 'product_type', 'base_price']);

        return view('sales.price-lists', [
            'priceLists'   => $priceLists,
            'productTypes' => self::PRODUCT_TYPES,
            'products'     => $products,
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

        $prices        = (array) $request->input('prices', []);
        $articlePrices = (array) $request->input('article_prices', []);

        DB::transaction(function () use ($prices, $articlePrices, $priceList) {
            // Prix par CATÉGORIE (product_id null).
            foreach ($prices as $type => $value) {
                if (! array_key_exists($type, self::PRODUCT_TYPES)) continue;

                if ($value === null || $value === '') {
                    $priceList->items()->whereNull('product_id')->where('product_type', $type)->delete();
                    continue;
                }

                SalePriceListItem::updateOrCreate(
                    ['sale_price_list_id' => $priceList->id, 'product_id' => null, 'product_type' => $type],
                    ['unit_price' => max(0, (float) $value)]
                );
            }

            // Prix par ARTICLE (product_id défini) — prioritaire sur la catégorie.
            foreach ($articlePrices as $productId => $value) {
                $product = \App\Models\Product::find($productId);
                if (! $product) continue;

                if ($value === null || $value === '') {
                    $priceList->items()->where('product_id', $product->id)->delete();
                    continue;
                }

                SalePriceListItem::updateOrCreate(
                    ['sale_price_list_id' => $priceList->id, 'product_id' => $product->id],
                    ['product_type' => $product->product_type, 'unit_price' => max(0, (float) $value)]
                );
            }
        });

        return back()->with('success', "Tarifs de « {$priceList->name} » mis à jour.");
    }

    /**
     * Carte des prix du catalogue pour un client donné (POS) : { product_id: prix }.
     * Permet de re-tarifer tout l'écran au changement de client en un seul appel.
     */
    public function catalogPrices(Request $request)
    {
        if (Gate::denies('commerce.L')) abort(403);

        $data = $request->validate(['client_id' => 'nullable|exists:clients,id']);
        $client = ! empty($data['client_id']) ? Client::find($data['client_id']) : null;

        $prices = \App\Models\Product::active()->with('stock')->get()
            ->mapWithKeys(fn ($p) => [
                $p->id => SalePriceList::priceForProduct($client, $p) ?? (float) $p->base_price,
            ]);

        return response()->json(['prices' => $prices]);
    }

    /**
     * Endpoint JSON du formulaire de vente : prix suggéré POUR TOUTES les façons
     * de désigner un article — le catalogue, un article de stock, ou un simple
     * type de produit.
     *
     * L'écran de vente n'appelait ceci que pour le catalogue et les lignes
     * libres. Ses sélecteurs « stock » et « lot » lisaient une SECONDE grille
     * tarifaire (`price_lists`), injectée côté client, sans filtre de palier ni
     * de client : le même carton d'œufs partait au tarif négocié du grossiste
     * s'il était choisi au catalogue, et au premier prix trouvé par nom de
     * produit s'il était choisi dans le stock. Une seule source désormais.
     *
     * La réponse dit aussi D'OÙ vient le prix : un chiffre dont on ignore la
     * provenance est un chiffre que personne ne peut contredire.
     */
    public function suggest(Request $request)
    {
        if (Gate::denies('commerce.L')) abort(403);

        $data = $request->validate([
            'client_id'    => 'nullable|exists:clients,id',
            'product_id'   => 'nullable|exists:products,id',
            'stock_id'     => 'nullable|exists:stocks,id',
            'product_type' => 'required_without_all:product_id,stock_id|nullable|string',
        ]);

        $client = ! empty($data['client_id']) ? Client::find($data['client_id']) : null;
        $product = null;

        if (! empty($data['product_id'])) {
            $product = \App\Models\Product::find($data['product_id']);
        } elseif (! empty($data['stock_id'])) {
            // Un article de stock rattaché au catalogue mérite son prix PAR
            // ARTICLE, et non le prix générique de sa catégorie.
            $product = \App\Models\Product::active()->where('stock_id', $data['stock_id'])->first();
        }

        return response()->json(
            SalePriceList::explainPrice($client, $product, $data['product_type'] ?? null)
        );
    }
}
