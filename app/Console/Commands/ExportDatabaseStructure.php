<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;

class ExportDatabaseStructure extends Command
{
    /**
     * Le nom et la signature de la commande.
     */
    protected $signature = 'db:export-structure {--format=markdown : Le format de sortie (markdown ou json)}';

    /**
     * La description de la commande.
     */
    protected $description = 'Extrait la structure complète de la base de données (Tables, Colonnes, Types)';

    /**
     * Exécuter la commande.
     */
    public function handle()
    {
        $this->info("🔍 Analyse du schéma de la base de données en cours...");

        try {
            $schema = DB::connection()->getSchemaBuilder();
            $tables = $schema->getTableListing();
            $structure = [];

            foreach ($tables as $tableName) {
                // Ignorer les tables système de Laravel si nécessaire
                if (in_array($tableName, ['migrations', 'failed_jobs', 'personal_access_tokens', 'password_reset_tokens'])) {
                    continue;
                }

                $this->line("📋 Extraction de la table : {$tableName}");
                
                $columns = $schema->getColumns($tableName);
                $indexes = $schema->getIndexes($tableName);
                $foreignKeys = $schema->getForeignKeys($tableName);

                $structure[$tableName] = [
                    'columns' => array_map(function ($col) {
                        return [
                            'name' => $col['name'],
                            'type' => $col['type_name'],
                            'nullable' => $col['nullable'],
                            'default' => $col['default'],
                        ];
                    }, $columns),
                    'indexes' => $indexes,
                    'foreign_keys' => $foreignKeys
                ];
            }

            $format = $this->option('format');

            if ($format === 'json') {
                $this->exportAsJson($structure);
            } else {
                $this->exportAsMarkdown($structure);
            }

        } catch (\Exception $e) {
            $this->error("❌ Erreur lors de l'extraction : " . $e->getMessage());
        }
    }

    /**
     * Export au format JSON brut (Idéal pour des scripts ou IA)
     */
    private function exportAsJson(array $structure)
    {
        $filePath = storage_path('app/database_structure.json');
        File::put($filePath, json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("✅ Structure exportée en JSON avec succès dans : {$filePath}");
    }

    /**
     * Export au format Markdown (Idéal pour la documentation de votre ERP)
     */
    private function exportAsMarkdown(array $structure)
    {
        $md = "# 🏗️ Structure de la Base de Données - AviSmart\n\n";
        $md .= "Généré automatiquement le : " . now()->toDateTimeString() . "\n\n";

        foreach ($structure as $table => $data) {
            $md .= "## 📊 Table : `{$table}`\n\n";
            $md .= "| Colonne | Type | Nullable | Défaut |\n";
            $md .= "| --- | --- | --- | --- |\n";

            foreach ($data['columns'] as $col) {
                $nullable = $col['nullable'] ? '✅' : '❌';
                $default = $col['default'] ?? '*aucun*';
                $md .= "| `{$col['name']}` | {$col['type']} | {$nullable} | {$default} |\n";
            }

            if (!empty($data['foreign_keys'])) {
                $md .= "\n**🔗 Clés Étrangères :**\n";
                foreach ($data['foreign_keys'] as $fk) {
                    $local = implode(', ', $fk['local_columns']);
                    $foreign = implode(', ', $fk['foreign_columns']);
                    $md .= "* `{$local}` -> `{$fk['foreign_table']}({$foreign})`\n";
                }
            }

            $md .= "\n---\n\n";
        }

        $filePath = storage_path('app/database_structure.md');
        File::put($filePath, $md);
        $this->info("✅ Structure exportée en Markdown avec succès dans : {$filePath}");
    }
}