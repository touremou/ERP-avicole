<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IDEMPOTENCE DE LA SYNCHRO — la dernière table qui manquait à l'appel.
 *
 * SyncService écrit sa propre règle en tête de fichier :
 *
 *     « IDEMPOTENCE par uuid généré côté terrain — DOUBLÉE D'INDEX UNIQUE EN BASE »
 *
 * et la migration 2026_07_02_000001 la justifie : le contrôle applicatif
 * `where('uuid')->exists()` suffit en série, mais deux rejeux réseau strictement
 * concurrents le passent tous les deux et créent un doublon. L'index unique est la
 * VRAIE garantie ; la vérification applicative n'est qu'un raccourci.
 *
 * `water_readings` portait un uuid de synchro SANS cet index — la seule table de la
 * base dans ce cas. Conséquence concrète : un ravitaillement de citerne rejoué (mauvais
 * réseau, double appui du technicien, deux appareils) pouvait être enregistré DEUX
 * fois, et `waterReadingCreate` ajoute le volume au niveau de la citerne après
 * l'insertion. Le doublon gonflait donc la citerne d'autant, et le coût du
 * ravitaillement était compté deux fois.
 *
 * PRUDENCE PROD : même garde que les migrations précédentes — la contrainte n'est
 * posée que si aucun doublon non-null n'existe déjà. Sur une base qui en porterait,
 * la migration passe sans rien casser et la sortie le signale : c'est à
 * l'exploitation d'arbitrer quel relevé garder, pas à une migration.
 */
return new class extends Migration
{
    private const TABLE = 'water_readings';

    private const INDEX = 'water_readings_uuid_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'uuid')) {
            return;
        }

        if ($this->indexExists()) {
            return;
        }

        $duplicates = DB::table(self::TABLE)
            ->whereNotNull('uuid')
            ->select('uuid')
            ->groupBy('uuid')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            // On ne tranche pas à la place de l'exploitation : deux relevés portant
            // le même uuid désignent le même geste, mais lequel des deux a déjà été
            // répercuté sur le niveau de la citerne n'est pas déductible d'ici.
            echo "  [!] {$duplicates} uuid en double dans " . self::TABLE
                . " : index unique NON posé. Dédoublonner, puis rejouer cette migration.\n";

            return;
        }

        Schema::table(self::TABLE, function ($table) {
            $table->unique('uuid', self::INDEX);
        });
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table(self::TABLE, fn ($table) => $table->dropUnique(self::INDEX));
        }
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn ($index) => $index['name'] === self::INDEX);
    }
};
