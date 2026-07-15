<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sous-produits d'abattage (spec Transformation E9) — sang, plumes,
 * viscères non comestibles : volumes et destination tracés (équarrissage,
 * vente, compost…). Registre insert-only comme les autres registres
 * sanitaires : la maîtrise des sous-produits fait partie du plan HACCP
 * (zone déchets du plan de nettoyage, absence de nuisibles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slaughter_byproducts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();     // idempotence sync (généré client)
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('slaughter_order_id')->nullable()->constrained('slaughter_orders');
            $t->string('type', 30);                     // sang | plumes | visceres | autre
            $t->decimal('quantity_kg', 8, 2);
            $t->string('destination', 30);              // equarrissage | vente | compost | dechets | autre
            $t->text('notes')->nullable();
            $t->foreignId('operator_id')->constrained('users');
            $t->timestamp('collected_at')->nullable();   // heure réelle (client) ; nullable = pas de ON UPDATE implicite
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->index(['type', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slaughter_byproducts');
    }
};
