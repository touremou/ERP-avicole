<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncInitializeCommand extends Command {
    protected $signature = 'erp:sync-init';
    protected $description = 'Initialise les UUID pour les données existantes afin de permettre le mode offline.';

    public function handle() {
        $tables = ['batches', 'incubations', 'health_checks', 'daily_checks', 'egg_productions'];

        foreach ($tables as $table) {
            $this->comment("Traitement de la table: {$table}...");
            
            // On traite par paquets (chunks) pour la performance industrielle
            DB::table($table)->whereNull('uuid')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'uuid' => (string) Str::uuid(),
                        'is_synced' => true,
                        'last_sync_at' => now(),
                    ]);
                }
            });
        }

        $this->info('✅ Initialisation terminée. La base est prête pour le mode offline.');
    }
}