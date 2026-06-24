<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coût unitaire des œufs mis à couver (process costing du couvoir).
 *
 * Permet de répercuter le coût des œufs (achetés au fournisseur ou collectés en
 * interne) sur les poussins éclos : coût/poussin = (eggs_count × egg_unit_cost)
 * / poussins éclos. Sans ce champ, les lots de poussins issus de l'éclosion
 * portaient un coût d'acquisition nul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            if (! Schema::hasColumn('incubations', 'egg_unit_cost')) {
                $table->decimal('egg_unit_cost', 12, 2)->default(0)->after('eggs_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            if (Schema::hasColumn('incubations', 'egg_unit_cost')) {
                $table->dropColumn('egg_unit_cost');
            }
        });
    }
};
