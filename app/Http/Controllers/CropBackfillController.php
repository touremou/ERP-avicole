<?php

namespace App\Http\Controllers;

use App\Services\Import\CropBackfillImporter;
use App\Services\Import\CropBackfillTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reprise d'historique CULTURES — téléchargement du modèle, analyse, import.
 *
 * Le parcours est volontairement en DEUX TEMPS : on téléverse, on lit le rapport
 * ligne par ligne, puis on valide. Importer des mois de données à l'aveugle et
 * découvrir à mi-parcours qu'une colonne était mal remplie laisserait une base
 * dont plus personne ne sait ce qui est entré — pire que pas d'import du tout.
 *
 * Le fichier téléversé est conservé le temps de l'aller-retour (dossier privé) :
 * l'analyse et la validation doivent porter sur le MÊME contenu, sinon on
 * validerait un fichier différent de celui qu'on a lu.
 */
class CropBackfillController extends Controller
{
    private const DISK = 'local';
    private const DIR  = 'imports/cultures';

    public function index()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.reprise.index', ['report' => session('backfill_report')]);
    }

    /** Modèle GÉNÉRÉ : ses listes reflètent la base au moment du téléchargement. */
    public function template(CropBackfillTemplate $template): StreamedResponse
    {
        abort_if(Gate::denies('cultures.C'), 403);

        $contents = $template->contents();
        $name = 'reprise-historique-cultures-' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(
            fn () => print($contents),
            $name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Analyse SANS écrire : rapport ligne par ligne. */
    public function analyse(Request $request, CropBackfillImporter $importer)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ], [], ['file' => 'fichier']);

        // Conservé pour que la validation porte sur le même contenu que l'analyse.
        $path = $request->file('file')->store(self::DIR, self::DISK);

        try {
            $analysis = $importer->analyse(Storage::disk(self::DISK)->path($path));
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            return back()->with('error', 'Fichier illisible : ' . $e->getMessage());
        }

        return redirect()->route('crop-backfill.index')->with('backfill_report', [
            'path'     => $path,
            'name'     => $request->file('file')->getClientOriginalName(),
            'counts'   => $analysis['counts'],
            'errors'   => array_slice($analysis['errors'], 0, 200),
            'error_total' => count($analysis['errors']),
            'existing' => $analysis['existing'],
            'ok'       => $analysis['ok'],
        ]);
    }

    /** Enregistre — tout ou rien. */
    public function commit(Request $request, CropBackfillImporter $importer)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate(['path' => 'required|string']);

        // Le chemin vient de la session côté vue, mais on ne fait pas confiance à
        // un champ posté : on le borne au dossier d'import.
        if (! str_starts_with($data['path'], self::DIR . '/') || ! Storage::disk(self::DISK)->exists($data['path'])) {
            return redirect()->route('crop-backfill.index')
                ->with('error', 'Fichier d\'import introuvable — recommencez le téléversement.');
        }

        try {
            $result = $importer->commit(Storage::disk(self::DISK)->path($data['path']), Auth::id());
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Une règle métier a refusé une ligne (délai avant récolte, pesée
            // manquante…) : rien n'a été enregistré, la transaction est annulée.
            return redirect()->route('crop-backfill.index')
                ->with('error', 'Import annulé — une règle métier a refusé une ligne : ' . collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            return redirect()->route('crop-backfill.index')
                ->with('error', 'Import annulé (aucune donnée enregistrée) : ' . $e->getMessage());
        }

        Storage::disk(self::DISK)->delete($data['path']);

        $created = $result['created'];
        $reused = $result['reused'];

        return redirect()->route('crop-cycles.index')->with('success', sprintf(
            'Reprise importée : %d parcelle(s), %d cycle(s), %d intrant(s), %d récolte(s).%s',
            $created['plots'], $created['cycles'], $created['inputs'], $created['harvests'],
            ($reused['plots'] + $reused['cycles']) > 0
                ? sprintf(' %d parcelle(s) et %d cycle(s) existaient déjà et ont été réutilisés.', $reused['plots'], $reused['cycles'])
                : '',
        ));
    }
}
