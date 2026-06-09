<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mill_productions', function (Blueprint $table) {
            // Rend la colonne optionnelle (permet de garder l'historique des vieilles données)
            $table->unsignedBigInteger('machine_id')->nullable()->change();
            
            // Note : Si tu n'as pas de vieilles données à conserver, tu pourrais même la supprimer :
            // $table->dropColumn('machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('mill_productions', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->nullable(false)->change();
        });
    }
};