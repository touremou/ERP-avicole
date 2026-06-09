<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stocks', 'feed_type')) {
            Schema::table('stocks', function (Blueprint $table) {
                // On l'ajoute juste après le nom de l'article pour que la BDD soit lisible
                $table->string('feed_type')->nullable()->after('item_name')
                      ->comment('Clé stricte pour la liaison avec les consommations (ex: Croissance, Ponte 1)');
            });
        }
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('feed_type');
        });
    }
};