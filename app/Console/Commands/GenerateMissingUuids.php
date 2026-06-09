<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateMissingUuids extends Command
{
    protected $signature = 'erp:generate-uuids';
    protected $description = 'Génère des UUID pour tous les enregistrements existants afin de préparer la synchronisation offline.';

    public function handle()
    {
        $tables = ['batches', 'incubations', 'health_checks', 'daily_checks', 'egg_productions'];

        foreach ($tables as $table) {
            $this->info("Traitement de la table : {$table}");
            
            DB::table($table)->whereNull('uuid')->chunkById(100, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'uuid' => (string) Str::uuid(),
                        'is_synced' => true, // Les données existantes sont déjà sur le serveur
                        'last_sync_at' => now(),
                    ]);
                }
            });
        }

        $this->info('✅ Tous les UUID ont été générés avec succès.');
    }
}