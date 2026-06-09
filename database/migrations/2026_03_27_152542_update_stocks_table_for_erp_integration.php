<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::table('stocks', function (Blueprint $table) {
        // On ajoute d'abord le prix
        if (!Schema::hasColumn('stocks', 'last_unit_price')) {
            $table->decimal('last_unit_price', 15, 2)->default(0)->after('alert_threshold');
        }
        
        // Puis on ajoute metadata
        if (!Schema::hasColumn('stocks', 'metadata')) {
            $table->json('metadata')->nullable()->after('last_unit_price');
        }
    });
}
};