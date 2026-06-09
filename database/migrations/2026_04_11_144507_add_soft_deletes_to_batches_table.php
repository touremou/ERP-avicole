<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    if (!Schema::hasColumn('batches', 'deleted_at')) {
        Schema::table('batches', function (Blueprint $table) {
            $table->softDeletes(); // Ajoute la colonne deleted_at
        });
    }
}

public function down()
{
    Schema::table('batches', function (Blueprint $table) {
        $table->dropSoftDeletes();
    });
}
};
