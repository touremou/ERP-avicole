<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use App\Models\Batch;
use App\Models\Incubator;
use App\Models\ProductionType;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use App\Services\UnitConverter;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartIncubation
{
    public function execute(array $data): Incubation
    {
        return DB::transaction(function () use ($data) {
            $incubator = Incubator::findOrFail($data['incubator_id']);
            $batchId = $this->resolveBatchId($data);
            
            // Durée : saisie explicite → espèce du lot → réglage de la ferme → 21.
            //
            // Le repli valait « 21 » en dur, donc la poule : une mise en couvoir
            // de canards sans durée saisie datait l'éclosion une SEMAINE trop tôt,
            // et le mirage avec elle. Le tableau par espèce existait pourtant, mais
            // dans un contrôleur — l'Action ne le voyait pas.
            $duration = isset($data['duration']) && $data['duration'] !== ''
                ? (int) $data['duration']
                : $this->resolveDuration($batchId);

            // Coût unitaire des œufs mis à couver : prix d'achat (œufs fournisseur)
            // ou valeur interne (œufs collectés). Repli sur un défaut paramétrable.
            $eggUnitCost = isset($data['egg_unit_cost']) && $data['egg_unit_cost'] !== ''
                ? (float) $data['egg_unit_cost']
                : (float) setting('couvoir.egg_unit_cost', 0);

            // Frais d'incubation du cycle (énergie, main-d'œuvre, amortissement) :
            // saisis, ou dérivés d'un taux paramétrable par œuf. Absorption complète.
            $overheadCost = isset($data['overhead_cost']) && $data['overhead_cost'] !== ''
                ? (float) $data['overhead_cost']
                : (float) $data['eggs_count'] * (float) setting('couvoir.overhead_per_egg', 0);

            /*
             * LES ŒUFS PRÉLEVÉS AU MAGASIN EN SORTENT.
             *
             * Cette action enregistrait un nombre d'œufs et un coût, sans jamais
             * toucher au stock. Des œufs collectés restaient donc comptés
             * VENDABLES pendant qu'ils étaient à l'incubateur — le magasin était
             * surévalué du contenu des machines.
             *
             * Depuis #305 ce n'est plus seulement un chiffre faux : une vente
             * déstocke et refuse quand le magasin est vide, mais un stock gonflé
             * par les œufs en incubation ne refuse pas. On pouvait vendre des
             * œufs physiquement enfermés dans une couveuse.
             *
             * On ne déduit QUE l'interne. Des œufs achetés à un fournisseur ne
             * sont jamais entrés au magasin : les déduire retirerait un stock qui
             * n'a jamais existé — c'est l'erreur symétrique, et elle serait aussi
             * coûteuse.
             */
            $source = $data['source_type'] ?? 'internal';
            $calibre = $data['egg_grade'] ?? null;

            if ($source === 'internal' && $calibre) {
                $this->destockEggs($calibre, (int) $data['eggs_count']);
            }

            $incubation = Incubation::create([
                'batch_id'            => $batchId,
                'source_type'         => $source,
                'egg_grade'           => $source === 'internal' ? $calibre : null,
                'incubator_id'        => $incubator->id,
                'code_incubation'     => 'INC-' . now()->format('ymd') . '-' . strtoupper(Str::random(4)),
                'start_date'          => $data['start_date'],
                'incubation_duration' => $duration,
                'hatch_date_expected' => Carbon::parse($data['start_date'])->addDays($duration),
                'eggs_count'          => $data['eggs_count'],
                'egg_unit_cost'       => $eggUnitCost,
                'overhead_cost'       => $overheadCost,
                'status'              => 'incubation'
            ]);

            $incubator->update(['status' => 'Occupé']);

            return $incubation;
        });
    }

    /**
     * Durée d'incubation du lot, d'après l'espèce — source unique
     * (Species::incubationDays). Repli sur le réglage de la ferme puis 21.
     */
    /**
     * Sort du magasin les œufs mis à couver, ou refuse en le disant.
     *
     * Deux refus, et tous deux sont nécessaires :
     *
     *   • STOCK INSUFFISANT. `syncMovement` plafonne silencieusement une sortie
     *     à zéro (cf. EggMovementController, qui contrôle pour la même raison) :
     *     sans ce test, mettre à couver plus d'œufs qu'on n'en a viderait le
     *     magasin sans rien dire, et la différence disparaîtrait ;
     *   • ARTICLE INTROUVABLE. `syncMovement` rend `false` sans lever quand le
     *     calibre n'existe pas au magasin — c'est exactement ce qui avait rendu
     *     tous les tris invisibles (#296). On regarde donc sa valeur de retour.
     */
    private function destockEggs(string $calibre, int $nombreOeufs): void
    {
        $quantite = UnitConverter::toStockBase((float) $nombreOeufs, 'Unité', Stock::CAT_OEUFS);

        $stock = Stock::where('category', Stock::CAT_OEUFS)
            ->where('item_name', $calibre)
            ->first();

        if (! $stock) {
            throw ValidationException::withMessages(['egg_grade' => sprintf(
                "Le calibre « %s » n'existe pas au magasin : impossible d'y prélever des œufs. "
                . "Enregistrez d'abord un tri de collecte, ou lancez « php artisan eggs:repair-stock ».",
                $calibre,
            )]);
        }

        if ((float) $stock->current_quantity < $quantite) {
            throw ValidationException::withMessages(['eggs_count' => sprintf(
                "Stock insuffisant pour le calibre « %s » : il faut %s alvéoles, le magasin en a %s.",
                $calibre,
                number_format($quantite, 2),
                number_format((float) $stock->current_quantity, 2),
            )]);
        }

        $ok = StockIntegrationService::syncMovement(
            $calibre,
            Stock::CAT_OEUFS,
            $quantite,
            'out',
            "Mise à couver — {$nombreOeufs} œufs, calibre {$calibre}",
            'Alvéole',
        );

        if ($ok === false) {
            throw ValidationException::withMessages(['egg_grade' =>
                "Le prélèvement au magasin a échoué pour le calibre « {$calibre} ». Mise à couver annulée.",
            ]);
        }
    }

    private function resolveDuration(int $batchId): int
    {
        $species = Batch::with('species')->find($batchId)?->species;

        return $species
            ? $species->incubationDays()
            : (int) setting('couvoir.incubation_days', 21);
    }

    private function resolveBatchId(array $data): int
    {
        if ($data['source_type'] === 'internal') {
            return (int) $data['batch_id'];
        }

        // 1. Le bâtiment virtuel de transit
        $externalBuilding = \App\Models\Building::firstOrCreate(
            ['name' => 'Zone Fournisseurs Externes'],
            [
                'type'        => 'reproducteur',
                'surface'     => 1,
                'capacity'    => 999999,
                'description' => 'Bâtiment virtuel de transit pour le traçage.'
            ]
        );

        // 2. Traitement du fournisseur (Création ou Récupération)
        if ($data['provider_id'] === 'new') {
            $provider = \App\Models\Provider::create([
                'name'  => $data['new_provider_name'],
                'phone' => $data['new_provider_phone'],
                'type'  => $data['new_provider_type'],
            ]);
        } else {
            $provider = \App\Models\Provider::findOrFail($data['provider_id']);
        }

        // 3. Détermination de l'employé responsable (Traçabilité ERP).
        // On lit la fiche employé RATTACHÉE au compte (relation users→employees),
        // sinon le premier employé disponible ; à défaut null (colonne nullable).
        // NB : ne jamais retomber sur auth()->id() (c'est un users.id, pas un
        // employees.id → violation de clé étrangère batches.employee_id).
        $employeeId = auth()->user()?->employee?->id
                      ?? \App\Models\Employee::query()->value('id');

        /// 4. Création du lot externe rattaché (Blindage absolu)
        $batch = \App\Models\Batch::firstOrCreate(
            ['code' => 'EXT-' . strtoupper(\Illuminate\Support\Str::slug($provider->name))],
            [
                // --- 1. Identifiants et Base ---
                'production_type_id'    => ProductionType::resolveOrCreate('reproducteur', null)->id,
                'status'                 => 'Actif',
                'building_id'            => $externalBuilding->id,
                'provider_id'            => $provider->id,
                'employee_id'            => $employeeId,
                'description'            => "Achat externe : " . $provider->name,

                // --- 2. Planification ---
                'arrival_date'           => now(),
                'expected_end_date'      => now()->addDays(21),
                'production_phase'       => 'Attente/Incubation',
                
                // --- 3. Quantités (Tout à 0 car ce sont des oeufs, pas des volailles) ---
                'initial_quantity'       => 0,
                'current_quantity'       => 0,
                'qty_alive'              => 0,
                'qty_dead'               => 0,
                'qty_males'              => 0,
                'qty_females'            => 0,
                
                // --- 4. Variables Zootechniques (Initialisées à vide/zéro) ---
                'age_at_arrival'         => 0,
                'avg_weight_start'       => 0,
                'mating_ratio'           => 0,
                'chick_state'            => 'Normal', // Valeur standard attendue par ton Enum/Validation
                'vaccination_received'   => false,
                'planned_density'        => 0,
                'arrival_mortality_rate' => 0,
                
                // --- 5. Variables Financières ---
                'buy_price_per_unit'     => 0,
                'total_acquisition_cost' => 0,
                'additional_costs'       => 0,
                
                // --- 6. Attributs Système ---
                'is_synced'              => 0,
            ]
        );

        return $batch->id;
    }
}
