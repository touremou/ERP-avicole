<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions de caisse : ouverture (fond de caisse) → clôture (comptage des
 * billets) → écart théorique/réel. Le théorique = fond + encaissements espèces
 * de la session ; le réel = somme comptée. L'écart révèle manquants/excédents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treasury_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable(); // théorique à la clôture
            $table->decimal('counted_cash', 14, 2)->nullable();  // réel compté
            $table->decimal('difference', 14, 2)->nullable();    // compté − théorique
            $table->json('denominations')->nullable();           // {20000: 3, 10000: 5, ...}
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};
