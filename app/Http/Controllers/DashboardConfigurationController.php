<?php

namespace App\Http\Controllers;

use App\Models\DashboardConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardConfigurationController extends Controller
{
    /**
     * Écran de personnalisation du tableau de bord de l'utilisateur courant.
     */
    public function edit()
    {
        $config = DashboardConfiguration::firstOrCreate(['user_id' => Auth::id()]);
        $hidden = $config->hidden_blocks ?? [];

        return view('dashboard-config', [
            'blocks' => DashboardConfiguration::BLOCKS,
            'hidden' => $hidden,
        ]);
    }

    /**
     * Enregistre les blocs visibles. Le formulaire envoie les blocs COCHÉS
     * (= visibles) ; on en déduit la liste des blocs masqués par différence
     * avec le catalogue, en ignorant toute clé inconnue.
     */
    public function update(Request $request)
    {
        $visible = (array) $request->input('visible', []);
        $allKeys = array_keys(DashboardConfiguration::BLOCKS);

        $hidden = array_values(array_diff($allKeys, $visible));

        DashboardConfiguration::updateOrCreate(
            ['user_id' => Auth::id()],
            ['hidden_blocks' => $hidden]
        );

        return redirect()->route('dashboard')->with('success', 'Tableau de bord personnalisé.');
    }
}
