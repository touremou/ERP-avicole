<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /** Catégories commerciales (alignées sur les groupes de prix / le formulaire de vente). */
    public const TYPES = SalePriceListController::PRODUCT_TYPES;

    public function index()
    {
        if (Gate::denies('commerce.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        $products = Product::orderBy('name')->paginate(40);

        return view('products.index', ['products' => $products, 'types' => self::TYPES]);
    }

    public function create()
    {
        if (Gate::denies('commerce.C')) return back()->with('error', 'Création non autorisée.');

        return view('products.create', ['types' => self::TYPES, 'stocks' => $this->stockOptions()]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('commerce.C')) return back()->with('error', 'Création non autorisée.');

        $data = $this->validated($request);
        $data['photo_path'] = $this->storePhoto($request);
        $data['is_active'] = $request->boolean('is_active', true);

        $product = Product::create($data);

        return redirect()->route('products.index')->with('success', "Article « {$product->name} » créé.");
    }

    public function edit(Product $product)
    {
        if (Gate::denies('commerce.M')) return back()->with('error', 'Modification non autorisée.');

        return view('products.edit', ['product' => $product, 'types' => self::TYPES, 'stocks' => $this->stockOptions()]);
    }

    public function update(Request $request, Product $product)
    {
        if (Gate::denies('commerce.M')) return back()->with('error', 'Modification non autorisée.');

        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($newPhoto = $this->storePhoto($request)) {
            // Remplace l'ancienne photo (nettoyage disque).
            if ($product->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
            }
            $data['photo_path'] = $newPhoto;
        }

        $product->update($data);

        return redirect()->route('products.index')->with('success', "Article « {$product->name} » mis à jour.");
    }

    public function destroy(Product $product)
    {
        if (Gate::denies('commerce.S')) return back()->with('error', 'Suppression non autorisée.');

        if ($product->photo_path) {
            Storage::disk('public')->delete($product->photo_path);
        }
        $product->delete();

        return back()->with('success', 'Article supprimé.');
    }

    // ─────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'         => 'required|string|max:255',
            'sku'          => 'nullable|string|max:100',
            'product_type' => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'stock_id'     => 'nullable|exists:stocks,id',
            'unit'         => 'required|string|max:30',
            'base_price'   => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
            'photo'        => 'nullable|image|max:4096', // 4 Mo
        ]);
    }

    /** Articles de stock proposables au lien (catégories vendables). */
    private function stockOptions()
    {
        return \App\Models\Stock::orderBy('category')->orderBy('item_name')
            ->get(['id', 'item_name', 'category', 'unit']);
    }

    private function storePhoto(Request $request): ?string
    {
        if (! $request->hasFile('photo')) {
            return null;
        }

        return $request->file('photo')->store('products/photos', 'public');
    }
}
