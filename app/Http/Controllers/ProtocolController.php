<?php

namespace App\Http\Controllers;

use App\Models\Protocol;
use App\Models\ProtocolStep;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Http\Requests\Protocol\StoreProtocolRequest;
use App\Http\Requests\Protocol\UpdateProtocolRequest;
use App\Http\Requests\Protocol\AddProtocolStepRequest;
use App\Actions\Protocol\CreateProtocol;
use App\Actions\Protocol\UpdateProtocol;
use App\Actions\Protocol\DuplicateProtocol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;

class ProtocolController extends Controller
{
    /**
     * DASHBOARD DES PROTOCOLES (Vue L)
     */
    public function index()
    {
        if (Gate::denies('elevage.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $protocols = Protocol::withCount(['steps', 'batches as active_batches_count' => function($query) {
            $query->where('status', 'Actif');
        }])->get();

        // Types de production de toutes les espèces, pour le sélecteur
        // "Type d'élevage" du modal de création (espèces non-volailles incluses).
        $productionTypes = ProductionType::active()->with('species')->orderBy('species_id')->get();

        return view('protocols.index', compact('protocols', 'productionTypes'));
    }

    /**
     * CRÉATION D'UN ITINÉRAIRE (Vue C)
     * Utilisation de FormRequest (StoreProtocolRequest) et d'Action (CreateProtocol)
     */
    public function store(StoreProtocolRequest $request, CreateProtocol $action)
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Action non autorisée.');
        try {
            $action->execute($request->validated());
            return redirect()->route('protocols.index')->with('success', 'Protocole sanitaire créé avec succès.');
        } catch (\Exception $e) {
            Log::error("Échec création protocole : " . $e->getMessage());
            return back()->withErrors(['error' => 'Erreur technique lors de la sauvegarde.'])->withInput();
        }
    }

    /**
     * LECTURE SEULE - DÉTAILS DU PROTOCOLE (Vue L)
     */
    public function show(Protocol $protocol)
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $protocol->load(['steps' => function($query) {
            $query->orderBy('day_number', 'asc');
        }]);

        return view('protocols.show', compact('protocol'));
    }

    /**
     * AFFICHAGE DU FORMULAIRE D'ÉDITION (Vue M)
     */
    public function edit(Protocol $protocol)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        $protocol->load('steps');
        $normModels = ProductionNorm::select('model_name', 'batch_type')->distinct()->get();
        $productionTypes = ProductionType::active()->with('species')->orderBy('species_id')->get();

        return view('protocols.edit', compact('protocol', 'normModels', 'productionTypes'));
    }

    /**
     * MISE À JOUR DU MASTER PROTOCOLE (Vue M)
     * Utilisation de FormRequest (UpdateProtocolRequest) et d'Action (UpdateProtocol)
     */
    public function update(UpdateProtocolRequest $request, Protocol $protocol, UpdateProtocol $action)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');
        try {
            $action->execute($protocol, $request->validated());
            return redirect()->route('protocols.index')->with('success', 'Protocole mis à jour avec succès.');
        } catch (\Exception $e) {
            Log::error("Échec mise à jour protocole : " . $e->getMessage());
            return back()->withErrors(['error' => 'Erreur technique : ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * AJOUT D'UNE ÉTAPE INDIVIDUELLE (Depuis la vue d'édition) (Vue M)
     */
    public function addStep(AddProtocolStepRequest $request, Protocol $protocol)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');
        $protocol->steps()->create($request->validated());
        return back()->with('success', 'Nouvelle intervention ajoutée au standard.');
    }

    /**
     * SUPPRESSION D'UNE ÉTAPE INDIVIDUELLE (Vue S)
     */
    public function destroyStep($id)
    {
        if (Gate::denies('elevage.S')) return back()->with('error', 'Privilèges insuffisants pour supprimer une étape master.');

        $step = ProtocolStep::findOrFail($id);
        $step->delete();

        return back()->with('success', 'L’étape a été retirée du standard avec succès.');
    }

    /**
     * SUPPRESSION COMPLÈTE DU PROTOCOLE (Vue S)
     */
    public function destroy(Protocol $protocol)
    {
        if (Gate::denies('elevage.S')) return back()->with('error', 'Privilèges insuffisants pour supprimer un protocole.');

        // Blocage de sécurité : Ne pas supprimer un protocole utilisé par un lot actif
        if ($protocol->batches()->where('status', 'Actif')->exists()) {
            return back()->with('error', 'Action annulée : Ce protocole est actuellement appliqué à un ou plusieurs lots actifs.');
        }

        DB::transaction(function () use ($protocol) {
            $protocol->steps()->delete();
            $protocol->delete();
        });
        
        return redirect()->route('protocols.index')->with('success', 'Protocole supprimé de la bibliothèque.');
    }

    /**
     * EXPORTATION SÉCURISÉE (JSON SIGNÉ)
     */
    public function export(Protocol $protocol)
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $protocol->load('steps');
        
        $payload = [
            'name'        => $protocol->name,
            'type'        => $protocol->type,
            'strain'      => $protocol->strain,
            'description' => $protocol->description,
            'steps'       => $protocol->steps->map(fn($s) => [
                'day_number'  => $s->day_number,
                'action_name' => $s->action_name,
                'type'        => $s->type,
                'method'      => $s->method,
            ])->toArray()
        ];

        // Sécurité contre la falsification offline
        $jsonContent = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonContent, config('app.key')); 

        $finalData = ['data' => $payload, 'checksum' => $signature];
        $fileName = 'PROTOC_'.strtoupper($protocol->type).'_'.now()->format('d_m_Y').'.json';
        
        return response()->streamDownload(function () use ($finalData) {
            echo json_encode($finalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $fileName);
    }

    /**
     * DUPLICATION RAPIDE (Vue C)
     * Utilisation de l'Action DuplicateProtocol
     */
    public function duplicate(Protocol $protocol, DuplicateProtocol $action)
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Action non autorisée.');

        try {
            $clone = $action->execute($protocol);
            return redirect()->route('protocols.edit', $clone->id)->with('success', 'Protocole dupliqué.');
        } catch (\Exception $e) {
            Log::error("Échec duplication protocole : " . $e->getMessage());
            return back()->with('error', 'Erreur lors de la duplication.');
        }
    }

    /**
     * IMPORTATION DE FICHIER JSON (Vue C)
     */
    public function import(Request $request)
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Action non autorisée.');

        $request->validate([
            'protocol_file' => 'required|file|mimes:json|max:2048',
        ]);

        try {
            $fileContent = file_get_contents($request->file('protocol_file')->getRealPath());
            $json = json_decode($fileContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) throw new \Exception("Le fichier JSON est corrompu ou mal formaté.");

            $items = isset($json['data']) ? [$json['data']] : (isset($json[0]) ? $json : [$json]);
            $importedCount = 0;
            $skippedCount = 0;

            DB::transaction(function () use ($items, &$importedCount, &$skippedCount) {
                $allowedStepTypes = ['Vaccin', 'Traitement', 'Vitamine', 'Désinfection'];

                foreach ($items as $data) {
                    if (empty($data['name']) || empty($data['type'])) continue;

                    if (Protocol::where('name', strip_tags($data['name']))->where('type', $data['type'])->exists()) {
                        $skippedCount++;
                        continue;
                    }

                    $newProtocol = Protocol::create([
                        'name'        => strip_tags($data['name']),
                        'type'        => $data['type'],
                        'strain'      => $data['strain'] ?? null,
                        'description' => $data['description'] ?? null,
                    ]);

                    if (isset($data['steps']) && is_array($data['steps'])) {
                        foreach ($data['steps'] as $step) {
                            $newProtocol->steps()->create([
                                'day_number'  => (int) ($step['day_number'] ?? 0),
                                'action_name' => strip_tags($step['action_name'] ?? 'Inconnu'),
                                'type'        => in_array($step['type'] ?? '', $allowedStepTypes) ? $step['type'] : 'Traitement',
                                'method'      => $step['method'] ?? 'Eau de boisson',
                            ]);
                        }
                    }
                    $importedCount++;
                }
            });

            return redirect()->route('protocols.index')->with('success', "Importation réussie : {$importedCount} modèles ajoutés, {$skippedCount} doublons ignorés.");

        } catch (\Exception $e) {
            Log::error("Import Protocoles: " . $e->getMessage());
            return back()->withErrors(['error' => 'Échec de l\'importation : ' . $e->getMessage()]);
        }
    }
}