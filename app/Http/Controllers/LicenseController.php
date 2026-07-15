<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * LicenseController — activation / renouvellement de l'abonnement (écran
 * « Prolongez la date de validité du projet »).
 *
 * Le client saisit son identifiant + le code de validité fourni par le
 * fournisseur ; le code est vérifié hors-ligne (signature Ed25519) puis
 * persisté. L'application se débloque immédiatement si la nouvelle échéance
 * est dans le futur.
 */
class LicenseController extends Controller
{
    public function __construct(private LicenseService $licenses) {}

    /** Écran d'activation / renouvellement. */
    public function edit()
    {
        $license = $this->licenses->current();
        $status  = $this->licenses->status();
        $vendor  = [
            'name'    => setting('licence.vendor_name', config('license.vendor.name')),
            'address' => setting('licence.vendor_address', config('license.vendor.address')),
            'phone'   => setting('licence.vendor_phone', config('license.vendor.phone')),
        ];

        return view('license.edit', compact('license', 'status', 'vendor'));
    }

    /** Active un code de validité (réservé aux administrateurs). */
    public function update(Request $request)
    {
        if (Gate::denies('admin.S')) {
            return back()->with('error', 'Seul un administrateur peut activer une licence.');
        }

        $data = $request->validate([
            'identifiant' => ['required', 'string', 'max:191'],
            'code'        => ['required', 'string'],
        ], [], [
            'identifiant' => 'identifiant',
            'code'        => 'code de validité',
        ]);

        try {
            $license = $this->licenses->activate($data['identifiant'], $data['code']);
        } catch (\Throwable $e) {
            return back()->withInput(['identifiant' => $data['identifiant']])
                ->with('error', 'Activation refusée : ' . $e->getMessage());
        }

        return redirect()->route('license.edit')->with(
            'success',
            "Abonnement activé jusqu'au " . $license->expires_at->translatedFormat('d M Y') . '.'
        );
    }
}
