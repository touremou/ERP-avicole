<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mouvements de stock hors-ligne (mode terrain).
 *
 * Un mouvement (entrée / sortie / ajustement) saisi hors-ligne porte un UUID
 * généré côté client. Le serveur s'en sert pour garantir l'idempotence : un
 * même mouvement rejoué par le réseau ne sera appliqué (incrément/décrément du
 * stock) qu'une seule fois.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('stock_movements', 'uuid')) {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            }
        });
    }
};
