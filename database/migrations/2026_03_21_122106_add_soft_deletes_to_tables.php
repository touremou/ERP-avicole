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
        Schema::table('buildings', function (Blueprint $table) { $table->softDeletes(); });
        Schema::table('providers', function (Blueprint $table) { $table->softDeletes(); });
        Schema::table('employees', function (Blueprint $table) { $table->softDeletes(); });
    }

    public function down()
    {
        Schema::table('buildings', function (Blueprint $table) { $table->dropSoftDeletes(); });
        Schema::table('providers', function (Blueprint $table) { $table->dropSoftDeletes(); });
        Schema::table('employees', function (Blueprint $table) { $table->dropSoftDeletes(); });
    }
};
