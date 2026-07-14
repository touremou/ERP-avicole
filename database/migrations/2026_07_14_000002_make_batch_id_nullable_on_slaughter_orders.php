<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un ordre d'abattage peut naître d'une RÉCEPTION EXTERNE (volailles d'un
 * éleveur tiers, CCP 1) sans lot d'élevage interne : batch_id devient
 * nullable — la garde applicative exige batch_id OU reception_id
 * (SlaughterController::storeOrder, required_without).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('batch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('slaughter_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('batch_id')->nullable(false)->change();
        });
    }
};
