<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('batches', function (Blueprint $blueprint) {
            // On renomme la colonne proprement
            $blueprint->renameColumn('breeding_type', 'type');
        });
    }

    public function down()
    {
        Schema::table('batches', function (Blueprint $blueprint) {
            $blueprint->renameColumn('type', 'breeding_type');
        });
    }
};